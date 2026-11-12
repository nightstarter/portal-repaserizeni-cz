<?php

namespace App\Integrations\LegacyShop;

use App\Enums\OrderType;
use App\Enums\RoutingCode;
use App\Enums\TechnicalType;
use App\Enums\WorkflowScenario;
use Carbon\CarbonImmutable;

class LegacyOrderData
{
    /**
     * @param  array<string, ?string>  $customer
     * @param  array<string, mixed>  $businessSnapshot
     */
    public function __construct(
        public readonly string $sourceOrderId,
        public readonly string $orderNo,
        public readonly OrderType $orderType,
        public readonly TechnicalType $technicalType,
        public readonly RoutingCode $routing,
        public readonly WorkflowScenario $workflowScenario,
        public readonly string $productCode,
        public readonly ?string $productName,
        public readonly ?string $vin,
        public readonly ?string $vehicle,
        public readonly string $customerHandover,
        public readonly array $customer,
        public readonly array $businessSnapshot,
        public readonly ?CarbonImmutable $sourceCreatedAt,
        public readonly string $payloadHash,
    ) {}
}
