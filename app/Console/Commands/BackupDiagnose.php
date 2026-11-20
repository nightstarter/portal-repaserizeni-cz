<?php

namespace App\Console\Commands;

use App\Backup\BackupDiagnoser;
use Illuminate\Console\Command;

final class BackupDiagnose extends Command
{
    protected $signature = 'backup:diagnose {--json : Emit machine-readable JSON}';

    protected $description = 'Run read-only MariaDB backup capability and schema-only probe diagnostics';

    public function handle(BackupDiagnoser $diagnoser): int
    {
        $result = $diagnoser->diagnose();
        if ($this->option('json')) {
            $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            foreach ($result['checks'] as $check) {
                $this->line(sprintf('[%s] %s', $check['status'], $check['name']));
                if ($check['status'] === 'ERROR') {
                    $this->line('  '.$check['code'].': '.$check['recommendation']);
                }
            }
        }

        return $result['status'] === 'ERROR' ? self::FAILURE : self::SUCCESS;
    }
}
