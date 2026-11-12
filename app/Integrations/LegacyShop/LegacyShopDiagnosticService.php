<?php

namespace App\Integrations\LegacyShop;

use Illuminate\Database\Connection;
use Throwable;

class LegacyShopDiagnosticService
{
    public function __construct(
        private readonly LegacyShopMapping $mapping,
        private readonly LegacySchemaInspector $schema,
        private readonly Connection $connection,
    ) {}

    /** @return array<string, mixed> */
    public function report(): array
    {
        $this->connection->getPdo();
        $tables = [];
        foreach ($this->schema->tables() as $table) {
            $columns = $this->schema->columns($table);
            sort($columns);
            $tables[] = ['name' => $table, 'columns' => $columns];
        }

        $prefix = (string) config('integrations.legacy_shop.prestashop14.table_prefix', 'ps_');
        $required = ['orders', 'customer', 'address', 'country', 'carrier', 'cart_vehicle_data', 'order_history', 'order_state_lang', 'order_detail', 'product', 'offer_vin_token'];

        return [
            'generated_at' => now()->utc()->toIso8601String(),
            'connection' => [
                'ok' => true,
                'driver' => $this->connection->getDriverName(),
                'server_version' => $this->schema->serverVersion(),
                'read_only_grants' => $this->readOnlyGrantStatus(),
            ],
            'tables' => $tables,
            'mapping_verified' => $this->mapping->verified(),
            'prestashop14' => [
                'table_prefix' => $prefix,
                'required_tables' => array_map(fn (string $suffix): array => [
                    'name' => $prefix.$suffix,
                    'present' => $this->schema->hasTable($prefix.$suffix),
                ], $required),
                'deposit_product_id' => PrestaShop14OrderReader::DEPOSIT_PRODUCT_ID,
                'pohoda_order_offset' => PrestaShop14OrderReader::POHODA_ORDER_OFFSET,
                'payment_authority' => 'order_history_state_2',
                'poptavky_used' => false,
            ],
        ];
    }

    private function readOnlyGrantStatus(): bool|string
    {
        if ($this->connection->getDriverName() !== 'mysql') {
            return 'not_applicable';
        }
        try {
            $rows = $this->connection->select('SHOW GRANTS');
            $grants = strtoupper(implode(' ', array_map(fn (object $row): string => implode(' ', (array) $row), $rows)));

            return preg_match('/\b(INSERT|UPDATE|DELETE|CREATE|ALTER|DROP|FILE|ALL PRIVILEGES)\b/', $grants) !== 1;
        } catch (Throwable) {
            return 'unknown';
        }
    }
}
