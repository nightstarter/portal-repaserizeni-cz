<?php

namespace App\Integrations\LegacyShop;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class LegacyShopDiagnosticService
{
    public function __construct(private readonly LegacyShopMapping $mapping) {}

    /**
     * @return array<string, mixed>
     */
    public function report(): array
    {
        $connection = DB::connection(LegacyOrderReader::CONNECTION);
        $connection->getPdo();
        $schema = Schema::connection(LegacyOrderReader::CONNECTION);
        $tables = $schema->getTableListing();
        sort($tables);
        $tableReport = [];

        foreach ($tables as $table) {
            $columns = $schema->getColumnListing($table);
            sort($columns);
            $tableReport[] = ['name' => $table, 'columns' => $columns];
        }

        $configuredTable = null;
        try {
            $configuredTable = $this->mapping->table();
        } catch (LegacyShopConfigurationException) {
            // An unconfigured mapping is an expected diagnostic state.
        }

        $configuredColumns = $configuredTable !== null && $schema->hasTable($configuredTable)
            ? $schema->getColumnListing($configuredTable)
            : [];
        $mapping = [];

        foreach ((array) config('integrations.legacy_shop.mapping.columns', []) as $semantic => $column) {
            $mapping[$semantic] = [
                'column' => is_string($column) && $column !== '' ? $column : null,
                'present' => is_string($column) && in_array($column, $configuredColumns, true),
            ];
        }

        return [
            'generated_at' => now()->utc()->toIso8601String(),
            'connection' => [
                'ok' => true,
                'driver' => $connection->getDriverName(),
                'read_only_grants' => $this->readOnlyGrantStatus($connection->getDriverName()),
            ],
            'tables' => $tableReport,
            'mapping_verified' => $this->mapping->verified(),
            'configured_table' => $configuredTable,
            'mapping' => $mapping,
            'anonymized_sample' => $this->anonymizedSample($configuredTable, $configuredColumns),
        ];
    }

    private function readOnlyGrantStatus(string $driver): bool|string
    {
        if ($driver !== 'mysql') {
            return 'not_applicable';
        }

        try {
            $rows = DB::connection(LegacyOrderReader::CONNECTION)->select('SHOW GRANTS');
            $grants = strtoupper(implode(' ', array_map(fn (object $row): string => implode(' ', (array) $row), $rows)));

            return preg_match('/\b(INSERT|UPDATE|DELETE|CREATE|ALTER|DROP|FILE|ALL PRIVILEGES)\b/', $grants) !== 1;
        } catch (Throwable) {
            return 'unknown';
        }
    }

    /**
     * @param  array<int, string>  $availableColumns
     * @return array<string, mixed>|null
     */
    private function anonymizedSample(?string $table, array $availableColumns): ?array
    {
        if ($table === null || $availableColumns === []) {
            return null;
        }

        $configured = array_filter(
            $this->mapping->configuredColumns(),
            fn (string $column): bool => in_array($column, $availableColumns, true),
        );
        if ($configured === []) {
            return null;
        }

        $row = DB::connection(LegacyOrderReader::CONNECTION)
            ->table($table)
            ->select(array_values(array_unique($configured)))
            ->first();
        if ($row === null) {
            return null;
        }

        $safeRawFields = ['payment_status', 'order_type', 'technical_type', 'routing', 'workflow_scenario', 'customer_handover'];
        $sample = [];

        foreach ($configured as $semantic => $column) {
            $value = $row->{$column} ?? null;
            if (in_array($semantic, $safeRawFields, true)) {
                $sample[$semantic] = ['raw_value' => $value === null ? null : mb_substr((string) $value, 0, 80)];
            } else {
                $sample[$semantic] = ['present' => $value !== null && trim((string) $value) !== ''];
            }
        }

        return $sample;
    }
}
