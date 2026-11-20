<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

final class BackupPlan extends Command
{
    protected $signature = 'backup:plan {--json : Emit machine-readable JSON}';

    protected $description = 'Print the Phase 8B backup plan without creating or changing data';

    public function handle(): int
    {
        $plan = [
            'status' => 'PLAN_ONLY',
            'writes_data' => false,
            'format_version' => (int) config('backups.format_version'),
            'included' => [
                'portal_mariadb', 'private_storage_archive', 'schema_and_row_counts',
                'deployed_git_revision', 'runtime_versions', 'sha256_manifest',
            ],
            'separate_protected_escrow' => [
                'env_and_app_key', 'apache_php_configuration', 'webserver_cron_export',
                'windows_task_scheduler_export', 'connector_protected_config_and_state',
            ],
            'excluded' => ['vendor', 'cache', 'sessions', 'temporary_files', 'test_artifacts', 'routine_logs', 'connector_spool'],
            'rules' => [
                'Backup target must be absolute and outside the repository, webroot and private storage.',
                'The .env, APP_KEY and encryption keys stay in separate protected escrow, never in the ordinary archive.',
                'Database credentials must come from the environment or a mode-0600 client file, never process arguments.',
                'Only a fully verified manifest may have status COMPLETE.',
            ],
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->info('Backup plan only; no data was written.');
            $this->table(['Included component'], array_map(fn (string $item): array => [$item], $plan['included']));
            foreach ($plan['rules'] as $rule) {
                $this->line('- '.$rule);
            }
        }

        return self::SUCCESS;
    }
}
