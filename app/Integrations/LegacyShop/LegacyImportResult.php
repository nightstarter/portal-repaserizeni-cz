<?php

namespace App\Integrations\LegacyShop;

class LegacyImportResult
{
    public int $scanned = 0;

    public int $imported = 0;

    public int $wouldImport = 0;

    public int $skipped = 0;

    public int $pending = 0;

    public int $errors = 0;

    /** @var array<int, array{order: string, issues: array<int, string>, warnings: array<int, string>}> */
    public array $validation = [];

    public function __construct(public readonly bool $dryRun) {}

    /** @param array<int, string> $issues @param array<int, string> $warnings */
    public function addValidation(PrestaShop14OrderCandidate $candidate, array $issues, array $warnings = []): void
    {
        $this->validation[] = [
            'order' => $candidate->fingerprint(),
            'issues' => array_values(array_unique($issues)),
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

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
            'pending' => $this->pending,
            'errors' => $this->errors,
        ];
    }
}
