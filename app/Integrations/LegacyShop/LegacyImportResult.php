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

    /** @var array<int, array<string, mixed>> */
    public array $validation = [];

    public function __construct(public readonly bool $dryRun) {}

    /** @param array<int, string> $issues @param array<int, string> $warnings */
    public function addValidation(PrestaShop14OrderCandidate $candidate, array $issues, array $warnings = [], array $context = []): void
    {
        $blockingWarnings = array_values(array_intersect($warnings, [
            'MAPPING_NOT_VERIFIED', 'ORDER_CLASSIFICATION_PENDING',
        ]));
        $this->validation[] = array_merge([
            'source_order_id' => $candidate->sourceOrderId,
            'candidate_hash' => $candidate->candidateHash(),
            'import_eligible' => $issues === [] && $blockingWarnings === [],
            'eligibility_reasons' => array_values(array_unique(array_merge($issues, $blockingWarnings))),
            'repair_type' => $candidate->repairBranch,
            'repair_subtype' => $candidate->repairSubtype,
            'product_reference' => $candidate->productCode,
            'raw_omacka' => $candidate->rawOmacka,
            'handover_type' => $candidate->customerHandover,
            'latest_state' => $candidate->latestStateId === null ? null : [
                'id' => $candidate->latestStateId,
                'name' => $candidate->latestStateName,
            ],
            'payment_accepted' => $candidate->paymentAcceptedHistory,
            'payment_eligibility' => $candidate->paymentAcceptedHistory ? 'ELIGIBLE' : 'NOT_ELIGIBLE',
            'issues' => array_values(array_unique($issues)),
            'warnings' => array_values(array_unique($warnings)),
        ], $context);
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
