<?php

namespace App\Integrations\LegacyShop;

class LegacyImportResult
{
    public int $scanned = 0;

    public int $imported = 0;

    public int $wouldImport = 0;

    public int $skipped = 0;

    public int $errors = 0;

    public function __construct(public readonly bool $dryRun) {}

    /**
     * @return array<string, int|bool>
     */
    public function toArray(): array
    {
        return [
            'dry_run' => $this->dryRun,
            'scanned' => $this->scanned,
            'imported' => $this->imported,
            'would_import' => $this->wouldImport,
            'skipped' => $this->skipped,
            'errors' => $this->errors,
        ];
    }
}
