<?php

namespace App\Integrations\LegacyShop;

use App\Enums\OrderType;
use App\Enums\RoutingCode;
use App\Enums\TechnicalType;
use App\Enums\WorkflowScenario;
use Carbon\CarbonImmutable;
use Throwable;

class LegacyOrderMapper
{
    public function __construct(private readonly LegacyShopMapping $mapping) {}

    public function map(PrestaShop14OrderCandidate $candidate): LegacyOrderData
    {
        $missing = $candidate->validationIssues;
        $classification = $this->mapping->classificationFor($candidate->productCode);
        $customerName = $candidate->customer['name'] ?? null;
        if ($customerName === null) {
            $missing[] = 'CUSTOMER_NAME_MISSING';
        }
        if ($candidate->productCode === null) {
            $missing[] = 'PRODUCT_CODE_MISSING';
        }

        $orderType = $this->orderType($candidate, $missing);
        $technicalType = $this->enum($classification, 'technical_type', TechnicalType::class, $missing);
        $routing = $this->enum($classification, 'routing', RoutingCode::class, $missing);
        $workflowScenario = $candidate->repairBranch === OrderType::CUSTOM_REBUILD->value
            ? WorkflowScenario::CUSTOM_REBUILD
            : $this->enum($classification, 'workflow_scenario', WorkflowScenario::class, $missing);

        if ($missing !== []) {
            throw new IncompleteLegacyOrderException(array_values(array_unique($missing)));
        }

        $sourceCreatedAt = null;
        if ($candidate->createdAt !== null) {
            try {
                $sourceCreatedAt = CarbonImmutable::parse(
                    $candidate->createdAt,
                    (string) config('database.connections.legacy_shop.timezone', '+00:00'),
                );
            } catch (Throwable) {
                throw new IncompleteLegacyOrderException(['CREATED_AT_INVALID']);
            }
        }

        $raw = [
            'source_order_id' => $candidate->sourceOrderId,
            'external_order_no' => $candidate->externalOrderNo,
            'product_code' => $candidate->productCode,
            'latest_state_id' => $candidate->latestStateId,
            'valid' => $candidate->valid,
            'approval_value' => $candidate->approvalValue,
            'repair_branch' => $candidate->repairBranch,
            'repair_subtype' => $candidate->repairSubtype,
        ];
        $hash = hash('sha256', json_encode($raw, JSON_THROW_ON_ERROR));

        return new LegacyOrderData(
            sourceOrderId: $candidate->sourceOrderId,
            orderNo: $candidate->externalOrderNo,
            orderType: $orderType,
            technicalType: $technicalType,
            routing: $routing,
            workflowScenario: $workflowScenario,
            productCode: $candidate->productCode,
            productName: $candidate->productName,
            vin: $candidate->vin,
            vehicle: $candidate->vehicle,
            customerHandover: $candidate->customerHandover,
            customer: $candidate->customer,
            businessSnapshot: [
                'legacy_payload_hash' => $hash,
                'source_created_at' => $sourceCreatedAt?->toIso8601String(),
                'price' => $candidate->price,
                'deposit' => $candidate->deposit,
                'legacy_latest_state_id' => $candidate->latestStateId,
                'legacy_approval_value' => $candidate->approvalValue,
                'legacy_repair_branch' => $candidate->repairBranch,
                'legacy_repair_subtype' => $candidate->repairSubtype,
            ],
            sourceCreatedAt: $sourceCreatedAt,
            payloadHash: $hash,
        );
    }

    /**
     * @param  array<string, string>  $classification
     * @param  class-string<\BackedEnum>  $enum
     * @param  array<int, string>  $missing
     */
    private function enum(array $classification, string $key, string $enum, array &$missing): ?\BackedEnum
    {
        $raw = $classification[$key] ?? null;
        $value = is_string($raw) ? $enum::tryFrom($raw) : null;
        if ($value === null) {
            $missing[] = strtoupper($key).'_UNCONFIRMED';
        }

        return $value;
    }

    /** @param array<int, string> $missing */
    private function orderType(PrestaShop14OrderCandidate $candidate, array &$missing): ?OrderType
    {
        $value = $candidate->repairBranch === null ? null : OrderType::tryFrom($candidate->repairBranch);
        if ($value === null) {
            $missing[] = 'ORDER_TYPE_UNCONFIRMED';
        }

        return $value;
    }
}
