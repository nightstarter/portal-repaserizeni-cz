<?php

namespace App\Integrations\LegacyShop;

use stdClass;

class PrestaShop14OrderCandidate
{
    /**
     * @param  array<string, ?string>  $customer
     * @param  array<int, string>  $validationIssues
     * @param  array<int, string>  $warnings
     */
    public function __construct(
        public readonly string $sourceOrderId,
        public readonly string $externalOrderNo,
        public readonly ?string $createdAt,
        public readonly array $customer,
        public readonly ?string $productCode,
        public readonly ?string $productName,
        public readonly ?string $vin,
        public readonly ?string $vehicle,
        public readonly ?int $carrierId,
        public readonly ?string $carrierName,
        public readonly ?string $customerHandover,
        public readonly bool $valid,
        public readonly ?int $latestStateId,
        public readonly ?string $latestStateName,
        public readonly ?string $latestStateAt,
        public readonly bool $paymentAcceptedHistory,
        public readonly ?string $paymentMethod,
        public readonly bool $totalPaidCovered,
        public readonly bool $hasCardPayment,
        public readonly ?string $paypalPaymentStatus,
        public readonly ?int $approvalValue,
        public readonly ?string $repairBranch,
        public readonly ?string $repairSubtype,
        public readonly ?string $price,
        public readonly ?string $deposit,
        public readonly int $steeringItemCount,
        public readonly array $validationIssues,
        public readonly array $warnings,
    ) {}

    public function fingerprint(): string
    {
        return substr(hash('sha256', 'legacy-order:'.$this->sourceOrderId), 0, 12);
    }

    public function candidateHash(): string
    {
        return hash('sha256', json_encode([
            'source_order_id' => $this->sourceOrderId,
            'external_order_no' => $this->externalOrderNo,
            'product_reference' => $this->productCode,
            'approval' => $this->approvalValue,
            'latest_state_id' => $this->latestStateId,
            'payment_accepted_history' => $this->paymentAcceptedHistory,
            'steering_item_count' => $this->steeringItemCount,
        ], JSON_THROW_ON_ERROR));
    }

    public function asLegacyRow(): stdClass
    {
        return (object) [
            'source_order_id' => $this->sourceOrderId,
            'order_no' => $this->externalOrderNo,
            'created_at' => $this->createdAt,
            'customer_name' => $this->customer['name'],
            'customer_company' => $this->customer['company'],
            'customer_email' => $this->customer['email'],
            'customer_phone' => $this->customer['phone'],
            'customer_street' => $this->customer['street'],
            'customer_city' => $this->customer['city'],
            'customer_postal_code' => $this->customer['postal_code'],
            'customer_country_code' => $this->customer['country_code'],
            'product_code' => $this->productCode,
            'product_name' => $this->productName,
            'vin' => $this->vin,
            'vehicle' => $this->vehicle,
            'customer_handover' => $this->customerHandover,
            'price' => $this->price,
            'deposit' => $this->deposit,
        ];
    }
}
