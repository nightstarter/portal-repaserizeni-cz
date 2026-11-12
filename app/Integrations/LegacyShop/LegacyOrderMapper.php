<?php

namespace App\Integrations\LegacyShop;

use App\Enums\OrderType;
use App\Enums\RoutingCode;
use App\Enums\TechnicalType;
use App\Enums\WorkflowScenario;
use Carbon\CarbonImmutable;
use stdClass;
use Throwable;

class LegacyOrderMapper
{
    public function __construct(private readonly LegacyShopMapping $mapping) {}

    public function map(stdClass $row): LegacyOrderData
    {
        $missing = [];
        $sourceOrderId = $this->requiredString($row, 'source_order_id', $missing);
        $orderNo = $this->requiredString($row, 'order_no', $missing);
        $productCode = $this->requiredString($row, 'product_code', $missing);
        $customerName = $this->requiredString($row, 'customer_name', $missing);

        if ($sourceOrderId !== null && filter_var($sourceOrderId, FILTER_VALIDATE_INT) === false) {
            $missing[] = 'source_order_id(integer)';
        }

        $orderType = $this->mappedEnum($row, 'order_type', OrderType::class, $missing);
        $technicalType = $this->mappedEnum($row, 'technical_type', TechnicalType::class, $missing);
        $routing = $this->mappedEnum($row, 'routing', RoutingCode::class, $missing);
        $workflowScenario = $this->mappedEnum($row, 'workflow_scenario', WorkflowScenario::class, $missing);
        $customerHandover = $this->mappedString($row, 'customer_handover', $missing);
        $sourceCreatedAt = $this->date($row, 'created_at', $missing);

        if ($missing !== []) {
            throw new IncompleteLegacyOrderException(array_values(array_unique($missing)));
        }

        $raw = $this->rawConfiguredValues($row);

        return new LegacyOrderData(
            sourceOrderId: $sourceOrderId,
            orderNo: $orderNo,
            orderType: $orderType,
            technicalType: $technicalType,
            routing: $routing,
            workflowScenario: $workflowScenario,
            productCode: $productCode,
            productName: $this->optionalString($row, 'product_name'),
            vin: $this->optionalString($row, 'vin'),
            vehicle: $this->optionalString($row, 'vehicle'),
            customerHandover: $customerHandover,
            customer: [
                'name' => $customerName,
                'company' => $this->optionalString($row, 'customer_company'),
                'email' => $this->optionalString($row, 'customer_email'),
                'phone' => $this->optionalString($row, 'customer_phone'),
                'street' => $this->optionalString($row, 'customer_street'),
                'city' => $this->optionalString($row, 'customer_city'),
                'postal_code' => $this->optionalString($row, 'customer_postal_code'),
                'country_code' => $this->optionalString($row, 'customer_country_code'),
            ],
            businessSnapshot: [
                'legacy_payload_hash' => hash('sha256', json_encode($raw, JSON_THROW_ON_ERROR)),
                'source_created_at' => $sourceCreatedAt?->toIso8601String(),
                'price' => $this->optionalString($row, 'price'),
                'deposit' => $this->optionalString($row, 'deposit'),
            ],
            sourceCreatedAt: $sourceCreatedAt,
            payloadHash: hash('sha256', json_encode($raw, JSON_THROW_ON_ERROR)),
        );
    }

    /**
     * @param  array<int, string>  $missing
     */
    private function requiredString(stdClass $row, string $semantic, array &$missing): ?string
    {
        $value = $this->optionalString($row, $semantic);
        if ($value === null) {
            $missing[] = $semantic;
        }

        return $value;
    }

    /**
     * @param  class-string<\BackedEnum>  $enum
     * @param  array<int, string>  $missing
     */
    private function mappedEnum(stdClass $row, string $semantic, string $enum, array &$missing): ?\BackedEnum
    {
        $mapped = $this->mappedString($row, $semantic, $missing);
        if ($mapped === null) {
            return null;
        }

        $value = $enum::tryFrom($mapped);
        if ($value === null) {
            $missing[] = $semantic.'(invalid-map-target)';
        }

        return $value;
    }

    /**
     * @param  array<int, string>  $missing
     */
    private function mappedString(stdClass $row, string $semantic, array &$missing): ?string
    {
        $raw = $this->value($row, $semantic);
        $mapped = $this->mapping->mapValue($semantic, $raw);
        if ($mapped === null) {
            $missing[] = $semantic.'(unmapped)';
        }

        return $mapped;
    }

    /**
     * @param  array<int, string>  $missing
     */
    private function date(stdClass $row, string $semantic, array &$missing): ?CarbonImmutable
    {
        $raw = $this->optionalString($row, $semantic);
        if ($raw === null) {
            return null;
        }

        try {
            return CarbonImmutable::parse($raw, 'UTC');
        } catch (Throwable) {
            $missing[] = $semantic.'(invalid-date)';

            return null;
        }
    }

    private function optionalString(stdClass $row, string $semantic): ?string
    {
        $value = $this->value($row, $semantic);
        if ($value === null || is_array($value) || is_object($value)) {
            return null;
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }

    private function value(stdClass $row, string $semantic): mixed
    {
        $column = $this->mapping->column($semantic);

        return $column === null ? null : ($row->{$column} ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    private function rawConfiguredValues(stdClass $row): array
    {
        $values = [];
        foreach ($this->mapping->configuredColumns() as $semantic => $column) {
            $values[$semantic] = $row->{$column} ?? null;
        }
        ksort($values);

        return $values;
    }
}
