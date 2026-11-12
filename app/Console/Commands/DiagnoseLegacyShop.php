<?php

namespace App\Console\Commands;

use App\Integrations\LegacyShop\LegacyShopDiagnosticService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Throwable;

class DiagnoseLegacyShop extends Command
{
    protected $signature = 'legacy-shop:diagnose {--output= : Path for the anonymized JSON report}';

    protected $description = 'Read-only legacy shop connection, schema and mapping diagnostic';

    public function handle(LegacyShopDiagnosticService $diagnostic): int
    {
        try {
            $report = $diagnostic->report();
            $output = $this->option('output') ?: storage_path('app/private/legacy-shop-mapping-report.json');
            File::ensureDirectoryExists(dirname($output));
            File::put($output, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL);

            $this->info('Legacy shop read-only diagnostic completed.');
            $this->line('Driver: '.$report['connection']['driver']);
            $this->line('Tables found: '.count($report['tables']));
            $this->line('Mapping verified: '.($report['mapping_verified'] ? 'yes' : 'no'));
            $this->line('Anonymized report: '.$output);

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error('Legacy shop diagnostic failed: '.class_basename($exception));

            return self::FAILURE;
        }
    }
}
