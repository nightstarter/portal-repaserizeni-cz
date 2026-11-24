<?php

namespace App\Console\Commands;

use App\Operations\MonitorCheck;
use App\Operations\MonitoringRunner;
use Illuminate\Console\Command;
use Throwable;

class OperationsMonitor extends Command
{
    protected $signature = 'ops:monitor
        {--json : Emit machine-readable JSON}
        {--no-notify : Persist monitoring state without contacting optional external channels}
        {--check= : Run one exact check key}';

    protected $description = 'Run persistent production monitoring checks safely';

    public function handle(MonitoringRunner $runner): int
    {
        $only = $this->option('check');
        $only = is_string($only) && $only !== '' ? $only : null;
        try {
            $execution = $runner->run($only, (bool) $this->option('no-notify'));
            $payload = $execution->toArray();
            $failed = $execution->hasErrors();
        } catch (Throwable) {
            $payload = [
                'status' => MonitorCheck::CRITICAL,
                'run_uuid' => null,
                'lock_contended' => false,
                'checks' => [[
                    'key' => 'monitor_internal',
                    'severity' => MonitorCheck::CRITICAL,
                    'summary' => 'Monitoring nelze bezpečně spustit; interní detail nebyl zveřejněn.',
                    'metadata' => ['failure_code' => 'MONITOR_BOOTSTRAP_FAILURE'],
                ]],
            ];
            $failed = true;
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            $this->table(['Stav', 'Kontrola', 'Výsledek'], array_map(
                fn (array $check): array => [$check['severity'], $check['key'], $check['summary']],
                $payload['checks'],
            ));
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
