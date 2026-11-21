<?php

namespace Tests\Feature;

use App\Backup\Contracts\DumpProcessRunner;
use App\Backup\DumpException;
use App\Backup\DumpFailureClassifier;
use App\Backup\DumpProcessResult;
use App\Backup\MariaDbClientConfig;
use App\Backup\MariaDbDumpCommand;
use App\Backup\MariaDbDumpRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

final class Phase8bDumpDiagnosticsTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    private string $binary;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/portal-dump-diagnostics-'.Str::uuid();
        mkdir($this->root, 0700);
        $this->binary = $this->root.'/mariadb-dump';
        file_put_contents($this->binary, "#!/bin/sh\nexit 99\n");
        chmod($this->binary, 0700);
        config([
            'backups.dump_binary' => $this->binary,
            'backups.diagnose_parent' => sys_get_temp_dir(),
            'backups.testing_database_configuration' => [
                'driver' => 'mysql', 'host' => 'db.internal.invalid', 'port' => 3306,
                'database' => 'portal_fixture', 'username' => 'fixture-user',
                'password' => 'fixture-secret', 'unix_socket' => '',
            ],
            'backups.testing_database_objects' => ['triggers' => 0, 'routines' => 0, 'events' => 0],
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    public function test_mariadb_1011_command_has_defaults_first_safe_core_options_and_no_unused_object_flags(): void
    {
        $process = new RecordingDumpProcessRunner;
        $this->app->instance(DumpProcessRunner::class, $process);

        $metadata = app(MariaDbDumpRunner::class)->dump($this->root.'/database.sql', $this->root);
        $command = $process->commands[0];

        $this->assertSame($this->binary, $command[0]);
        $this->assertStringStartsWith('--defaults-extra-file=', $command[1]);
        $this->assertContains('--single-transaction', $command);
        $this->assertContains('--quick', $command);
        $this->assertContains('--skip-lock-tables', $command);
        $this->assertContains('--no-tablespaces', $command);
        $this->assertContains('--skip-triggers', $command);
        $this->assertNotContains('--triggers', $command);
        $this->assertNotContains('--routines', $command);
        $this->assertNotContains('--events', $command);
        $this->assertSame('portal_fixture', end($command));
        $this->assertFalse(collect($command)->contains(fn (string $argument): bool => str_starts_with($argument, '--result-file=')));
        $this->assertSame(['triggers' => 0, 'routines' => 0, 'events' => 0], $metadata['logical_objects']);
        $this->assertSame([], glob($this->root.'/.mariadb-client-*.cnf'));
    }

    public function test_existing_logical_objects_are_explicit_and_permission_failure_is_not_hidden(): void
    {
        config(['backups.testing_database_objects' => ['triggers' => 2, 'routines' => 1, 'events' => 1]]);
        $process = new RecordingDumpProcessRunner;
        $process->dumpResult = new DumpProcessResult(2, '', 'Access denied; you need (at least one of) the SUPER privilege(s)');
        $this->app->instance(DumpProcessRunner::class, $process);

        try {
            app(MariaDbDumpRunner::class)->dump($this->root.'/database.sql', $this->root);
            $this->fail('Permission failure was expected.');
        } catch (DumpException $exception) {
            $this->assertSame('DUMP_PERMISSION_DENIED', $exception->failureCode);
            $this->assertSame(2, $exception->processExitCode);
            $this->assertStringNotContainsString('SUPER', json_encode($exception->safeDetails()));
        }
        $command = $process->commands[0];
        $this->assertContains('--triggers', $command);
        $this->assertContains('--routines', $command);
        $this->assertContains('--events', $command);
        $this->assertFileDoesNotExist($this->root.'/database.sql');
        $this->assertSame([], glob($this->root.'/.mariadb-client-*.cnf'));
    }

    public function test_client_config_safely_selects_tcp_or_socket_and_escapes_fake_values(): void
    {
        $writer = app(MariaDbClientConfig::class);
        $tcp = $writer->create($this->root, [
            'host' => 'db"fixture', 'port' => '3307', 'username' => 'user\\fixture',
            'password' => 'fake"secret', 'unix_socket' => '',
        ]);
        $contents = file_get_contents($tcp);
        $this->assertStringContainsString('protocol="tcp"', $contents);
        $this->assertStringContainsString('host="db\\"fixture"', $contents);
        $this->assertStringContainsString('port="3307"', $contents);
        $this->assertStringContainsString('user="user\\\\fixture"', $contents);
        $this->assertStringContainsString('password="fake\\"secret"', $contents);
        $this->assertSame('600', substr(sprintf('%o', fileperms($tcp)), -3));
        unlink($tcp);

        $socket = $writer->create($this->root, [
            'host' => 'ignored', 'port' => '3306', 'username' => 'fixture',
            'password' => 'fixture', 'unix_socket' => '/run/mysqld/mysqld.sock',
        ]);
        $contents = file_get_contents($socket);
        $this->assertStringContainsString('protocol="socket"', $contents);
        $this->assertStringContainsString('socket="/run/mysqld/mysqld.sock"', $contents);
        $this->assertStringNotContainsString('host=', $contents);
        $this->assertStringNotContainsString('port=', $contents);
        unlink($socket);
        $this->assertSame([], glob($this->root.'/.mariadb-client-*.cnf'));
    }

    public function test_all_dump_failures_have_stable_safe_classification_without_stderr_disclosure(): void
    {
        $classifier = app(DumpFailureClassifier::class);
        $secret = 'user=portal password=fixture-secret host=db.internal.invalid dsn=mysql:private';
        $cases = [
            'DUMP_AUTHENTICATION_FAILED' => "Access denied for user {$secret}",
            'DUMP_CONNECTION_FAILED' => "Can't connect to server {$secret}",
            'DUMP_PERMISSION_DENIED' => "Access denied; you need (at least one of) SELECT {$secret}",
            'DUMP_UNSUPPORTED_OPTION' => "unknown option --unsafe {$secret}",
            'DUMP_OUTPUT_WRITE_FAILED' => "No space left on device {$secret}",
            'DUMP_FAILED_UNKNOWN' => "unexpected opaque failure {$secret}",
        ];
        foreach ($cases as $expected => $stderr) {
            $exception = $classifier->fromResult(
                new DumpProcessResult(2, '', $stderr),
                '/usr/bin/mariadb-dump',
                ['triggers' => 0, 'routines' => 0, 'events' => 0],
            );
            $safe = json_encode($exception->safeDetails(), JSON_THROW_ON_ERROR);
            $this->assertSame($expected, $exception->failureCode);
            $this->assertStringNotContainsString('fixture-secret', $safe);
            $this->assertStringNotContainsString('db.internal.invalid', $safe);
            $this->assertStringNotContainsString('portal', $safe);
        }

        foreach (['DUMP_PROCESS_START_FAILED', 'DUMP_TIMEOUT', 'DUMP_OUTPUT_WRITE_FAILED'] as $code) {
            $exception = new DumpException($code, 'dump_process', null, 'mariadb-dump', 'Safe recommendation.');
            $this->assertSame($code, $exception->safeDetails()['code']);
        }
        config(['backups.dump_binary' => $this->root.'/missing-binary']);
        try {
            app(MariaDbDumpCommand::class)->binary();
            $this->fail('Missing binary was expected.');
        } catch (DumpException $exception) {
            $this->assertSame('DUMP_BINARY_NOT_EXECUTABLE', $exception->failureCode);
        }
    }

    public function test_diagnose_runs_schema_only_probe_with_json_exit_zero_and_cleans_own_files(): void
    {
        $before = glob(sys_get_temp_dir().'/portal-backup-diagnose-*');
        $process = new RecordingDumpProcessRunner;
        $this->app->instance(DumpProcessRunner::class, $process);

        $this->assertSame(0, Artisan::call('backup:diagnose', ['--json' => true]));
        $result = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('OK', $result['status']);
        $this->assertSame('10.11.14-MariaDB', collect($result['checks'])->firstWhere('name', 'dump_client_version')['version']);
        $this->assertSame('OK', collect($result['checks'])->firstWhere('name', 'schema_only_probe')['status']);
        $this->assertSame('OK', collect($result['checks'])->firstWhere('name', 'data_dump_probe')['status']);
        $probeCommand = collect($process->commands)->first(fn (array $command): bool => in_array('--no-data', $command, true));
        $this->assertIsArray($probeCommand);
        $this->assertContains('--skip-triggers', $probeCommand);
        $this->assertNotContains('--routines', $probeCommand);
        $this->assertNotContains('--events', $probeCommand);
        $dataProbeCommand = collect($process->commands)->first(fn (array $command): bool => in_array('--where=1 LIMIT 1', $command, true));
        $this->assertIsArray($dataProbeCommand);
        $this->assertSame('migrations', end($dataProbeCommand));
        $this->assertContains('--no-create-info', $dataProbeCommand);
        $this->assertNotContains('--no-data', $dataProbeCommand);
        $this->assertSame($before, glob(sys_get_temp_dir().'/portal-backup-diagnose-*'));
        $this->assertStringNotContainsString('fixture-secret', Artisan::output());
        $this->assertStringNotContainsString('db.internal.invalid', Artisan::output());
    }

    public function test_diagnose_reports_object_permission_start_and_timeout_failures_safely(): void
    {
        config(['backups.testing_database_objects' => ['triggers' => 1, 'routines' => 1, 'events' => 1]]);
        $process = new RecordingDumpProcessRunner;
        $process->dumpResult = new DumpProcessResult(2, '', 'Access denied; you need privilege password=fixture-secret');
        $this->app->instance(DumpProcessRunner::class, $process);
        $this->assertSame(1, Artisan::call('backup:diagnose', ['--json' => true]));
        $result = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $probe = collect($result['checks'])->firstWhere('name', 'schema_only_probe');
        $this->assertSame('DUMP_PERMISSION_DENIED', $probe['code']);
        $dataProbe = collect($result['checks'])->firstWhere('name', 'data_dump_probe');
        $this->assertSame('DUMP_PERMISSION_DENIED', $dataProbe['code']);
        $this->assertStringNotContainsString('fixture-secret', Artisan::output());

        foreach (['DUMP_PROCESS_START_FAILED', 'DUMP_TIMEOUT'] as $code) {
            $process = new RecordingDumpProcessRunner;
            $process->failure = new DumpException($code, 'dump_process', null, 'mariadb-dump', 'Safe recommendation.');
            $this->app->instance(DumpProcessRunner::class, $process);
            $this->assertSame(1, Artisan::call('backup:diagnose', ['--json' => true]));
            $this->assertStringContainsString($code, Artisan::output());
        }
    }
}

final class RecordingDumpProcessRunner implements DumpProcessRunner
{
    /** @var list<list<string>> */
    public array $commands = [];

    public ?DumpException $failure = null;

    public DumpProcessResult $dumpResult;

    public function __construct()
    {
        $this->dumpResult = new DumpProcessResult(0, '', '');
    }

    public function run(array $command, ?string $stdoutPath, int $timeoutSeconds): DumpProcessResult
    {
        $this->commands[] = $command;
        if ($this->failure !== null) {
            throw $this->failure;
        }
        if (in_array('--version', $command, true)) {
            return new DumpProcessResult(0, 'mariadb-dump Ver 10.19 Distrib 10.11.14-MariaDB', '');
        }
        if ($stdoutPath !== null) {
            file_put_contents($stdoutPath, '-- schema or isolated fake dump');
            chmod($stdoutPath, 0600);
        }

        return $this->dumpResult;
    }
}
