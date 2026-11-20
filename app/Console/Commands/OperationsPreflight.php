<?php

namespace App\Console\Commands;

use App\Operations\PreflightCheck;
use App\Operations\ProductionPreflight;
use Illuminate\Console\Command;

class OperationsPreflight extends Command
{
    protected $signature = 'ops:preflight {--json : Emit machine-readable JSON}';

    protected $description = 'Read-only production readiness preflight';

    public function handle(ProductionPreflight $preflight): int
    {
        $checks = $preflight->run();
        if ($this->option('json')) {
            $this->line((string) json_encode([
                'status' => $preflight->hasErrors($checks) ? PreflightCheck::ERROR : (collect($checks)->contains(fn (PreflightCheck $check): bool => $check->status === PreflightCheck::WARN) ? PreflightCheck::WARN : PreflightCheck::OK),
                'checks' => array_map(fn (PreflightCheck $check): array => $check->toArray(), $checks),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            $this->table(['Stav', 'Kontrola', 'Výsledek'], array_map(fn (PreflightCheck $check): array => [$check->status, $check->id, $check->message], $checks));
        }

        return $preflight->hasErrors($checks) ? self::FAILURE : self::SUCCESS;
    }
}
