<?php

namespace App\Integrations\LegacyShop;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;

class PrestaShop14OrderReader
{
    public const CONNECTION = 'legacy_shop';

    public const DEPOSIT_PRODUCT_ID = 91173;

    public const POHODA_ORDER_OFFSET = 4001;

    public function __construct(
        private readonly Connection $connection,
        private readonly LegacySchemaInspector $schema,
    ) {}

    /**
     * @return array<int, PrestaShop14OrderCandidate>
     */
    public function read(LegacyImportBoundary $boundary, ?string $checkpoint, int $limit): array
    {
        $this->assertLimit($limit);
        $query = $this->baseQuery();
        $boundary->applyPrestaShop14($query);

        if ($checkpoint !== null && $checkpoint !== '') {
            if (filter_var($checkpoint, FILTER_VALIDATE_INT) === false) {
                throw new LegacyShopConfigurationException('Stored legacy checkpoint is not an integer.');
            }
            $query->where('o.id_order', '>', (int) $checkpoint);
        }

        return array_map(fn (object $row): PrestaShop14OrderCandidate => $this->candidate($row), $query
            ->orderBy('o.id_order')
            ->limit($limit)
            ->get()
            ->all());
    }

    public function readOne(int $sourceOrderId): ?PrestaShop14OrderCandidate
    {
        $row = $this->baseQuery()->where('o.id_order', $sourceOrderId)->first();

        return $row === null ? null : $this->candidate($row);
    }

    private function baseQuery(): Builder
    {
        $this->assertRequiredSchema();
        $prefix = $this->prefix();
        $latest = $this->connection->table($prefix.'order_history as latest_source')
            ->select('latest_source.id_order')
            ->selectRaw('MAX(latest_source.id_order_history) AS id_order_history')
            ->groupBy('latest_source.id_order');

        return $this->connection->table($prefix.'orders as o')
            ->join($prefix.'customer as c', 'c.id_customer', '=', 'o.id_customer')
            ->join($prefix.'address as a', 'a.id_address', '=', 'o.id_address_invoice')
            ->leftJoin($prefix.'country as country', 'country.id_country', '=', 'a.id_country')
            ->leftJoin($prefix.'carrier as carrier', 'carrier.id_carrier', '=', 'o.id_carrier')
            ->leftJoinSub($latest, 'latest', fn ($join) => $join->on('latest.id_order', '=', 'o.id_order'))
            ->leftJoin($prefix.'order_history as history', 'history.id_order_history', '=', 'latest.id_order_history')
            ->select([
                'o.id_order', 'o.id_cart', 'o.id_carrier', 'o.date_add', 'o.payment',
                'o.total_paid', 'o.total_paid_real', 'o.valid', 'o.schvaleno',
                'c.firstname as customer_firstname', 'c.lastname as customer_lastname', 'c.email as customer_email',
                'a.firstname as address_firstname', 'a.lastname as address_lastname', 'a.company as customer_company',
                'a.address1 as customer_street', 'a.city as customer_city', 'a.postcode as customer_postal_code',
                'a.phone as customer_phone', 'a.phone_mobile as customer_mobile', 'country.iso_code as customer_country_code',
                'carrier.name as carrier_name', 'history.id_order_state as latest_state_id', 'history.date_add as latest_state_at',
            ]);
    }

    private function candidate(object $order): PrestaShop14OrderCandidate
    {
        $prefix = $this->prefix();
        $details = $this->connection->table($prefix.'order_detail as detail')
            ->join($prefix.'product as product', 'product.id_product', '=', 'detail.product_id')
            ->where('detail.id_order', $order->id_order)
            ->where('detail.product_id', '<>', self::DEPOSIT_PRODUCT_ID)
            ->select('detail.product_id', 'detail.product_reference', 'detail.product_name', 'product.deposit_amount')
            ->get();
        $issues = [];
        if ($details->count() === 0) {
            $issues[] = 'STEERING_ITEM_MISSING';
        } elseif ($details->count() > 1) {
            $issues[] = 'STEERING_ITEM_AMBIGUOUS';
        }
        $detail = $details->count() === 1 ? $details->first() : null;

        $vehicle = $this->connection->table($prefix.'cart_vehicle_data')->where('id_cart', $order->id_cart)->first();
        $hasCardPayment = $this->schema->hasTable($prefix.'payment_cc')
            && $this->connection->table($prefix.'payment_cc')->where('id_order', $order->id_order)->exists();
        $paypalStatus = $this->schema->hasTable($prefix.'paypal_order')
            ? $this->connection->table($prefix.'paypal_order')->where('id_order', $order->id_order)->value('payment_status')
            : null;

        $valid = (int) $order->valid === 1;
        $latestStateId = $order->latest_state_id === null ? null : (int) $order->latest_state_id;
        $paidStateIds = array_map('intval', (array) config('integrations.legacy_shop.prestashop14.paid_state_ids', [2]));
        if (! $valid || ! in_array($latestStateId, $paidStateIds, true)) {
            $issues[] = 'PAYMENT_AMBIGUOUS';
        }
        $totalCovered = (float) $order->total_paid > 0 && (float) $order->total_paid_real >= (float) $order->total_paid;
        if (! $totalCovered) {
            $issues[] = 'PAYMENT_AMOUNT_MISMATCH';
        }

        $carrierId = $order->id_carrier === null ? null : (int) $order->id_carrier;
        $handoverMap = (array) config('integrations.legacy_shop.prestashop14.carrier_handover_map', []);
        $handover = $carrierId === null ? null : ($handoverMap[(string) $carrierId] ?? null);
        if (! is_string($handover) || $handover === '') {
            $issues[] = 'CUSTOMER_HANDOVER_UNCONFIRMED';
            $handover = null;
        }

        $warnings = [];
        $warnings[] = $valid ? 'PAYMENT_EVIDENCE_VALID_TRUE' : 'PAYMENT_EVIDENCE_VALID_FALSE';
        $warnings[] = $latestStateId === null ? 'PAYMENT_EVIDENCE_STATE_MISSING' : 'PAYMENT_EVIDENCE_STATE_'.$latestStateId;
        $warnings[] = $hasCardPayment ? 'PAYMENT_EVIDENCE_CARD_PRESENT' : 'PAYMENT_EVIDENCE_CARD_ABSENT';
        $warnings[] = $paypalStatus === null ? 'PAYMENT_EVIDENCE_PAYPAL_ABSENT' : 'PAYMENT_EVIDENCE_PAYPAL_PRESENT';
        $warnings[] = $totalCovered ? 'PAYMENT_TOTAL_COVERED_NOT_STANDALONE_PROOF' : 'PAYMENT_TOTAL_NOT_COVERED';
        $approval = $order->schvaleno === null ? null : (int) $order->schvaleno;
        if (in_array($approval, [1, 2], true)) {
            $warnings[] = 'LEGACY_APPROVAL_REPORTED_NOT_MAPPED';
        }

        $name = $this->joinNonEmpty([$order->address_firstname, $order->address_lastname])
            ?? $this->joinNonEmpty([$order->customer_firstname, $order->customer_lastname]);
        $phone = $this->clean($order->customer_phone) ?? $this->clean($order->customer_mobile);
        $vehicleText = $vehicle === null ? null : $this->joinNonEmpty([
            $vehicle->make ?? null, $vehicle->model ?? null, $vehicle->year ?? null,
            $vehicle->fuel ?? null, $vehicle->power ?? null, $vehicle->capacity ?? null,
        ]);

        return new PrestaShop14OrderCandidate(
            sourceOrderId: (string) $order->id_order,
            externalOrderNo: (string) (self::POHODA_ORDER_OFFSET + (int) $order->id_order),
            createdAt: $this->clean($order->date_add),
            customer: [
                'name' => $name,
                'company' => $this->clean($order->customer_company),
                'email' => $this->clean($order->customer_email),
                'phone' => $phone,
                'street' => $this->clean($order->customer_street),
                'city' => $this->clean($order->customer_city),
                'postal_code' => $this->clean($order->customer_postal_code),
                'country_code' => $this->clean($order->customer_country_code),
            ],
            productCode: $detail === null ? null : $this->clean($detail->product_reference),
            productName: $detail === null ? null : $this->clean($detail->product_name),
            vin: $vehicle === null ? null : $this->clean($vehicle->vin ?? null),
            vehicle: $vehicleText,
            carrierId: $carrierId,
            carrierName: $this->clean($order->carrier_name),
            customerHandover: $handover,
            valid: $valid,
            latestStateId: $latestStateId,
            latestStateAt: $this->clean($order->latest_state_at),
            paymentMethod: $this->clean($order->payment),
            totalPaidCovered: $totalCovered,
            hasCardPayment: $hasCardPayment,
            paypalPaymentStatus: $this->clean($paypalStatus),
            approvalValue: $approval,
            price: $this->clean($order->total_paid),
            deposit: $detail === null ? null : $this->clean($detail->deposit_amount),
            steeringItemCount: $details->count(),
            validationIssues: array_values(array_unique($issues)),
            warnings: $warnings,
        );
    }

    private function assertRequiredSchema(): void
    {
        $prefix = $this->prefix();
        $required = [
            'orders' => ['id_order', 'id_customer', 'id_address_invoice', 'id_cart', 'valid', 'schvaleno', 'date_add'],
            'customer' => ['id_customer', 'firstname', 'lastname', 'email'],
            'address' => ['id_address', 'id_country', 'firstname', 'lastname'],
            'country' => ['id_country', 'iso_code'],
            'carrier' => ['id_carrier', 'name'],
            'cart_vehicle_data' => ['id_cart', 'vin'],
            'order_history' => ['id_order_history', 'id_order', 'id_order_state'],
            'order_detail' => ['id_order', 'product_id', 'product_reference', 'product_name'],
            'product' => ['id_product', 'deposit_amount'],
        ];
        foreach ($required as $suffix => $columns) {
            $table = $prefix.$suffix;
            if (! $this->schema->hasTable($table)) {
                throw new LegacyShopConfigurationException("Required PrestaShop table {$table} is missing.");
            }
            $missing = array_diff($columns, $this->schema->columns($table));
            if ($missing !== []) {
                throw new LegacyShopConfigurationException("Required PrestaShop columns are missing from {$table}: ".implode(', ', $missing));
            }
        }
    }

    private function prefix(): string
    {
        $prefix = (string) config('integrations.legacy_shop.prestashop14.table_prefix', 'ps_');
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $prefix) !== 1) {
            throw new LegacyShopConfigurationException('Unsafe PrestaShop table prefix.');
        }

        return $prefix;
    }

    private function assertLimit(int $limit): void
    {
        if ($limit < 1 || $limit > 1000) {
            throw new LegacyShopConfigurationException('Import limit must be between 1 and 1000.');
        }
    }

    /** @param array<int, mixed> $values */
    private function joinNonEmpty(array $values): ?string
    {
        $values = array_values(array_filter(array_map($this->clean(...), $values)));

        return $values === [] ? null : implode(' ', $values);
    }

    private function clean(mixed $value): ?string
    {
        if ($value === null || is_array($value) || is_object($value)) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
