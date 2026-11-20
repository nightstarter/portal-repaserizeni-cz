<?php

require dirname(__DIR__).DIRECTORY_SEPARATOR.'src'.DIRECTORY_SEPARATOR.'autoload.php';

use PortalRepase\Bridge\BridgeApplication;
use PortalRepase\Bridge\BridgeException;
use PortalRepase\Bridge\CanonicalRequest;
use PortalRepase\Bridge\CommandHandler;
use PortalRepase\Bridge\Config;
use PortalRepase\Bridge\FileSpool;
use PortalRepase\Bridge\HttpClient;
use PortalRepase\Bridge\ProcessLock;
use PortalRepase\Bridge\QuantityCalculator;
use PortalRepase\Bridge\SafeLogger;
use PortalRepase\Bridge\SnapshotChunker;
use PortalRepase\Bridge\StockReader;
use PortalRepase\Bridge\Util;

class BridgeFakeStockReader extends StockReader
{
    private $rows;

    public function __construct(array $rows)
    {
        $this->rows = $rows;
    }

    public function readAll()
    {
        return $this->rows;
    }

    public function inspect($limit)
    {
        return array_slice($this->rows, 0, $limit);
    }
}

$tests = 0;
$assertions = 0;

function bridge_test($name, $callback)
{
    global $tests;
    $callback();
    $tests++;
    echo 'PASS '.$name.PHP_EOL;
}

function bridge_assert($condition, $message)
{
    global $assertions;
    $assertions++;
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function bridge_throws($code, $callback)
{
    try {
        $callback();
    } catch (BridgeException $exception) {
        bridge_assert($exception->safeCode() === $code, 'Unexpected exception code '.$exception->safeCode());
        return;
    }
    throw new RuntimeException('Expected exception '.$code);
}

function bridge_temp_dir()
{
    $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'pohoda-bridge-test-'.bin2hex(random_bytes(8));
    mkdir($path, 0700, true);
    return $path;
}

function bridge_remove_dir($path)
{
    if (! is_dir($path) || strpos(realpath($path), realpath(sys_get_temp_dir())) !== 0 || strpos(basename($path), 'pohoda-bridge-test-') !== 0) {
        throw new RuntimeException('Unsafe test cleanup path');
    }
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($path);
}

function bridge_config($directory)
{
    return array(
        'environment' => 'testing', 'portal_base_url' => 'https://portal.example.test',
        'bridge_key_id' => 'test-key', 'bridge_secret_base64' => base64_encode(str_repeat('A', 32)),
        'connector_id' => 'test-connector', 'connector_version' => 'test',
        'sql_dsn' => 'sqlsrv:Server=invalid;Database=invalid', 'sql_username' => 'reader', 'sql_password' => 'password-marker',
        'warehouse_ref' => 1, 'warehouse_code' => 'DECIN', 'quantity_mode' => 'physical',
        'connect_timeout_seconds' => 1, 'request_timeout_seconds' => 1, 'batch_size' => 2, 'command_limit' => 10,
        'spool_max_bytes' => 100000, 'spool_max_age_hours' => 24, 'retry_base_seconds' => 10, 'retry_max_seconds' => 100,
        'log_max_bytes' => 100000, 'log_retention_days' => 1, 'dry_run' => false,
        'var_dir' => $directory, 'spool_dir' => $directory.DIRECTORY_SEPARATOR.'spool',
        'state_file' => $directory.DIRECTORY_SEPARATOR.'state.json', 'log_file' => $directory.DIRECTORY_SEPARATOR.'bridge.log',
        'lock_file' => $directory.DIRECTORY_SEPARATOR.'bridge.lock',
    );
}

try {
    bridge_test('canonical query and portal HMAC parity vector', function () {
        $query = array('z' => 'last', 'a' => 'hello world', 'nested' => array('b' => '2', 'a' => '1'));
        $canonical = CanonicalRequest::query($query);
        bridge_assert($canonical === 'a=hello%20world&nested%5Ba%5D=1&nested%5Bb%5D=2&z=last', 'Canonical query mismatch');
        $signature = CanonicalRequest::signature(str_repeat('A', 32), 'GET', '/api/internal/v1/commands', $query, '1700000000', '0123456789abcdef0123456789abcdef', '');
        bridge_assert($signature === '98ca040e1cb2df045afc2d0a64d21b1a7463fb05ae28487210427ff06c8ea7f4', 'HMAC vector mismatch');
        bridge_assert(strpos(CanonicalRequest::value('POST', '/x', array(), '1', '1234567890123456', "{\n}"), hash('sha256', "{\n}")) !== false, 'Raw body hash mismatch');
    });

    bridge_test('nonces are unique and cryptographically shaped', function () {
        $first = Util::nonce();
        $second = Util::nonce();
        bridge_assert($first !== $second, 'Nonce reused');
        bridge_assert((bool) preg_match('/^[A-Za-z0-9_-]{32}$/', $first), 'Nonce shape invalid');
    });

    bridge_test('quantity mode blocks uncertainty and calculates physical and free', function () {
        bridge_throws('QUANTITY_MODE_UNCONFIRMED', function () { QuantityCalculator::calculate(10, 3, 'unconfirmed'); });
        bridge_assert(QuantityCalculator::calculate(10, 3, 'physical')['available_quantity'] === 10, 'Physical mismatch');
        bridge_assert(QuantityCalculator::calculate(10, 3, 'free')['available_quantity'] === 7, 'Free mismatch');
        bridge_assert(QuantityCalculator::calculate(2, 5, 'free')['available_quantity'] === 0, 'Free floor mismatch');
    });

    bridge_test('snapshot chunks are deterministic complete and keep exact references', function () {
        $config = array('quantity_mode' => 'physical', 'batch_size' => 2, 'warehouse_code' => 'DECIN');
        $rows = array(
            array('ID' => 1, 'IDS' => ' AbC-1 ', 'StavZ' => 5, 'Rezer' => 1, 'DatSave' => 'v1'),
            array('ID' => 2, 'IDS' => 'XYZ', 'StavZ' => 2, 'Rezer' => 0, 'DatSave' => 'v2'),
            array('ID' => 3, 'IDS' => 'third', 'StavZ' => 1, 'Rezer' => 0, 'DatSave' => 'v3'),
        );
        $built = (new SnapshotChunker())->build($rows, $config, '2026-11-20T10:00:00Z', '00000000-0000-4000-8000-000000000010');
        bridge_assert(count($built['chunks']) === 2, 'Chunk count mismatch');
        $first = json_decode($built['chunks'][0]['payload'], true);
        $last = json_decode($built['chunks'][1]['payload'], true);
        bridge_assert($first['items'][0]['product_reference'] === 'AbC-1', 'Reference was normalized beyond trim');
        bridge_assert($first['final_chunk'] === false && $last['final_chunk'] === true, 'Finalization marker mismatch');
        bridge_assert($first['snapshot_run_id'] === $last['snapshot_run_id'], 'Run ID mismatch');
    });

    bridge_test('spool preserves exact payload and stable batch id over retry', function () {
        $directory = bridge_temp_dir();
        try {
            $spool = new FileSpool($directory, 10000, 24);
            $chunk = array('batch_id' => 'batch-one', 'run_id' => 'run-one', 'payload' => '{"exact": true}');
            $spool->enqueue($chunk);
            $entry = $spool->oldestReady();
            $spool->retry($entry, 1);
            $later = $spool->oldestReady(time() + 2);
            bridge_assert($later['payload'] === $chunk['payload'], 'Payload bytes changed');
            bridge_assert($later['meta']['batch_id'] === 'batch-one', 'Batch ID changed');
            $spool->acknowledge($later);
            bridge_assert($spool->count() === 0, 'Spool acknowledge failed');
        } finally {
            bridge_remove_dir($directory);
        }
    });

    bridge_test('spool refuses capacity and expired unsent data', function () {
        $directory = bridge_temp_dir();
        try {
            $spool = new FileSpool($directory, 1024, 1);
            bridge_throws('SPOOL_CAPACITY_EXCEEDED', function () use ($spool) {
                $spool->enqueue(array('batch_id' => 'big', 'run_id' => 'run', 'payload' => str_repeat('x', 2000)));
            });
        } finally {
            bridge_remove_dir($directory);
        }
    });

    bridge_test('local process lock excludes a second instance', function () {
        $directory = bridge_temp_dir();
        try {
            $first = new ProcessLock();
            $second = new ProcessLock();
            $path = $directory.DIRECTORY_SEPARATOR.'run.lock';
            $first->acquire($path);
            bridge_throws('CONNECTOR_ALREADY_RUNNING', function () use ($second, $path) { $second->acquire($path); });
            $first->release();
            $second->acquire($path);
            $second->release();
            bridge_assert(true, 'Lock was not reusable');
        } finally {
            bridge_remove_dir($directory);
        }
    });

    bridge_test('HTTP client always enforces TLS and signs exact body', function () {
        $directory = bridge_temp_dir();
        try {
            $captured = null;
            $client = new HttpClient(bridge_config($directory), function ($request) use (&$captured) {
                $captured = $request;
                return array('status' => 202, 'headers' => array(), 'body' => '{}');
            });
            $client->signed('POST', '/api/internal/v1/heartbeat', array(), '{"x":1}');
            bridge_assert($captured['verify_peer'] === true && $captured['verify_host'] === 2, 'TLS verification disabled');
            bridge_assert(strpos(implode("\n", $captured['headers']), 'X-Bridge-Signature: ') !== false, 'Signature missing');
            bridge_assert($captured['body'] === '{"x":1}', 'Raw body changed');
        } finally {
            bridge_remove_dir($directory);
        }
    });

    bridge_test('retry policy handles timeout status rate limit and server failures', function () {
        bridge_assert(HttpClient::shouldRetry(408), '408 not retryable');
        bridge_assert(HttpClient::shouldRetry(429), '429 not retryable');
        bridge_assert(HttpClient::shouldRetry(503), '503 not retryable');
        bridge_assert(! HttpClient::shouldRetry(422), '422 unexpectedly retryable');
        bridge_assert(HttpClient::retryAfter(array('headers' => array('retry-after' => '17')), 3) === 17, 'Retry-After ignored');
        bridge_assert(Util::backoffSeconds(3, 10, 100, 0) === 40, 'Backoff mismatch');
    });

    bridge_test('stock SQL is parameterized select without NOLOCK or mutation', function () {
        $sql = StockReader::stockSql();
        bridge_assert(stripos($sql, 'FROM SKz') !== false && strpos($sql, ':warehouse_ref') !== false, 'SQL parameters missing');
        bridge_assert(stripos($sql, 'NOLOCK') === false, 'NOLOCK found');
        bridge_assert(! preg_match('/\b(INSERT|UPDATE|DELETE|ALTER|EXEC)\b/i', $sql), 'Mutating SQL found');
    });

    bridge_test('commands reject SQL and only testing NOOP can execute', function () {
        $handler = new CommandHandler();
        bridge_assert($handler->execute(array('type' => 'NOOP', 'payload' => array()), 'production')['status'] === 'ERROR', 'Production NOOP executed');
        bridge_assert($handler->execute(array('type' => 'NOOP', 'payload' => array()), 'testing')['status'] === 'SUCCESS', 'Testing NOOP rejected');
        bridge_assert($handler->execute(array('type' => 'NOOP', 'payload' => array('sql' => 'SELECT 1')), 'testing')['external_code'] === 'SQL_PAYLOAD_FORBIDDEN', 'SQL payload accepted');
    });

    bridge_test('heartbeat contains no host paths DSN or credentials', function () {
        $directory = bridge_temp_dir();
        try {
            $config = bridge_config($directory);
            $application = new BridgeApplication($config, new StockReader($config), new HttpClient($config, function () {
                throw new RuntimeException('Network must not be used');
            }));
            $payload = Util::json($application->heartbeatPayload('DEGRADED', array('SAFE_CODE')));
            foreach (array($config['sql_dsn'], $config['sql_username'], $config['sql_password'], $directory, php_uname('n')) as $forbidden) {
                bridge_assert(strpos($payload, $forbidden) === false, 'Heartbeat leaked internal data');
            }
        } finally {
            bridge_remove_dir($directory);
        }
    });

    bridge_test('sync dry run performs no HTTP request and creates chunk report', function () {
        $directory = bridge_temp_dir();
        try {
            $config = bridge_config($directory);
            $networkCalls = 0;
            $http = new HttpClient($config, function () use (&$networkCalls) {
                $networkCalls++;
                return array('status' => 500, 'headers' => array(), 'body' => '');
            });
            $reader = new BridgeFakeStockReader(array(
                array('ID' => 1, 'IDS' => 'ONE', 'StavZ' => 1, 'Rezer' => 0, 'DatSave' => 'v1', 'RefSklad' => 1, 'RelSkTyp' => 1),
            ));
            $result = (new BridgeApplication($config, $reader, $http))->syncOnce(true);
            bridge_assert($result['status'] === 'DRY_RUN' && $result['chunks'] === 1, 'Dry run report invalid');
            bridge_assert($networkCalls === 0, 'Dry run used network');
        } finally {
            bridge_remove_dir($directory);
        }
    });

    bridge_test('logger sanitizes secret DSN password and dangerous keys', function () {
        $directory = bridge_temp_dir();
        try {
            $file = $directory.DIRECTORY_SEPARATOR.'bridge.log';
            $logger = new SafeLogger($file, 10000, 1, array('secret-marker', 'dsn-marker'));
            $logger->write('error', 'SAFE_ERROR', array('password' => 'password-marker', 'note' => 'secret-marker dsn-marker'));
            $log = file_get_contents($file);
            bridge_assert(strpos($log, 'secret-marker') === false && strpos($log, 'dsn-marker') === false && strpos($log, 'password-marker') === false, 'Log leaked sensitive data');
        } finally {
            bridge_remove_dir($directory);
        }
    });

    bridge_test('configuration rejects short secret and non HTTPS portal', function () {
        $directory = bridge_temp_dir();
        try {
            $config = bridge_config($directory);
            $config['bridge_secret_base64'] = base64_encode('short');
            bridge_throws('BRIDGE_SECRET_TOO_SHORT', function () use ($config) { Config::validate($config); });
            $config = bridge_config($directory);
            $config['portal_base_url'] = 'http://portal.example.test';
            bridge_throws('PORTAL_HTTPS_REQUIRED', function () use ($config) { Config::validate($config); });
        } finally {
            bridge_remove_dir($directory);
        }
    });

    bridge_test('connector source stays within PHP 7.4 syntax contract', function () {
        $forbidden = array('/\?->/', '/#\[/', '/\breadonly\b/', '/\benum\s+[A-Za-z_]/', '/\bmatch\s*\(/', '/\bstr_(contains|starts_with|ends_with)\s*\(/');
        foreach (array(dirname(__DIR__).DIRECTORY_SEPARATOR.'src', dirname(__DIR__).DIRECTORY_SEPARATOR.'bin', dirname(__DIR__).DIRECTORY_SEPARATOR.'config') as $root) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $source = file_get_contents($file->getPathname());
                    foreach ($forbidden as $pattern) {
                        bridge_assert(! preg_match($pattern, $source), 'PHP 8 syntax/API in '.$file->getFilename());
                    }
                }
            }
        }
    });

    echo 'Connector tests: '.$tests.' passed ('.$assertions.' assertions)'.PHP_EOL;
    exit(0);
} catch (Exception $exception) {
    fwrite(STDERR, 'FAIL '.$exception->getMessage().PHP_EOL);
    exit(1);
}
