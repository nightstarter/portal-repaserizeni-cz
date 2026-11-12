<?php

namespace App\Integrations\LegacyShop;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use stdClass;

class LegacyOrderReader
{
    public const CONNECTION = 'legacy_shop';

    public function __construct(private readonly LegacyShopMapping $mapping) {}

    /**
     * @return array<int, stdClass>
     */
    public function read(LegacyImportBoundary $boundary, ?string $checkpoint, int $limit): array
    {
        $this->mapping->assertImportReady();
        $this->assertMappedSchemaExists();

        if ($limit < 1 || $limit > 1000) {
            throw new LegacyShopConfigurationException('Import limit must be between 1 and 1000.');
        }

        $sourceId = $this->mapping->column('source_order_id');
        $paymentStatus = $this->mapping->column('payment_status');
        $query = DB::connection(self::CONNECTION)
            ->table($this->mapping->table())
            ->select(array_values(array_unique($this->mapping->configuredColumns())))
            ->whereIn($paymentStatus, $this->mapping->paidValues());

        $boundary->apply($query, $this->mapping);

        if ($checkpoint !== null && $checkpoint !== '') {
            if (filter_var($checkpoint, FILTER_VALIDATE_INT) === false) {
                throw new LegacyShopConfigurationException('Stored legacy checkpoint is not an integer.');
            }
            $query->where($sourceId, '>', (int) $checkpoint);
        }

        return $query->orderBy($sourceId)->limit($limit)->get()->all();
    }

    private function assertMappedSchemaExists(): void
    {
        $schema = Schema::connection(self::CONNECTION);
        $table = $this->mapping->table();

        if (! $schema->hasTable($table)) {
            throw new LegacyShopConfigurationException('Configured legacy orders table does not exist.');
        }

        $available = $schema->getColumnListing($table);
        $missing = array_diff(array_values($this->mapping->configuredColumns()), $available);

        if ($missing !== []) {
            throw new LegacyShopConfigurationException('Configured legacy columns do not exist: '.implode(', ', $missing));
        }
    }
}
