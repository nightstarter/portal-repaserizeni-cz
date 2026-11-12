<?php

namespace App\Integrations\LegacyShop;

use App\Contracts\CreatesInitialProcessThreads;
use App\Enums\OrderSource;
use App\Models\IntegrationCheckpoint;
use App\Models\IntegrationEvent;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use stdClass;
use Throwable;

class LegacyShopImportService
{
    public const INTEGRATION = 'LEGACY_SHOP_ORDERS';

    public function __construct(
        private readonly LegacyShopMapping $mapping,
        private readonly LegacyOrderReader $reader,
        private readonly LegacyOrderMapper $mapper,
        private readonly CreatesInitialProcessThreads $threadCreator,
    ) {}

    public function run(bool $dryRun = true, ?int $limit = null): LegacyImportResult
    {
        if (! $dryRun && ! config('integrations.legacy_shop.enabled')) {
            throw new LegacyShopConfigurationException('Legacy shop import is disabled.');
        }

        $this->mapping->assertImportReady();
        $boundary = LegacyImportBoundary::fromConfig();
        $limit ??= (int) config('integrations.legacy_shop.batch_size', 100);
        $checkpoint = IntegrationCheckpoint::query()->where('integration', self::INTEGRATION)->first();
        $rows = $this->reader->read($boundary, $checkpoint?->last_processed_id, $limit);
        $result = new LegacyImportResult($dryRun);

        foreach ($rows as $row) {
            $result->scanned++;

            try {
                $data = $this->mapper->map($row);

                if ($dryRun) {
                    if ($this->orderExists($data->sourceOrderId)) {
                        $result->skipped++;
                    } else {
                        $result->wouldImport++;
                    }

                    continue;
                }

                if ($this->importOne($data)) {
                    $result->imported++;
                } else {
                    $result->skipped++;
                }
            } catch (Throwable $exception) {
                $result->errors++;

                if (! $dryRun) {
                    $this->recordFailure($row, $exception);
                }

                break;
            }
        }

        return $result;
    }

    private function importOne(LegacyOrderData $data): bool
    {
        return DB::transaction(function () use ($data): bool {
            $existing = Order::query()
                ->where('source', OrderSource::ESHOP)
                ->where('source_order_id', $data->sourceOrderId)
                ->first();

            if ($existing !== null) {
                $this->markProcessed($data);
                $this->advanceCheckpoint($data->sourceOrderId);

                return false;
            }

            $order = Order::query()->create([
                'order_no' => $data->orderNo,
                'source' => OrderSource::ESHOP,
                'source_order_id' => $data->sourceOrderId,
                'order_type' => $data->orderType,
                'technical_type' => $data->technicalType,
                'routing' => $data->routing,
                'product_code' => $data->productCode,
                'product_name_snapshot' => $data->productName,
                'vin' => $data->vin,
                'vehicle' => $data->vehicle,
                'customer_handover' => $data->customerHandover,
                'product_verification_status' => 'UNVERIFIED',
                'business_snapshot' => $data->businessSnapshot,
                'imported_at' => now(),
            ]);

            $order->customer()->create($data->customer);
            $this->threadCreator->createFor($order, $data->workflowScenario);
            $this->markProcessed($data);
            $this->advanceCheckpoint($data->sourceOrderId);

            return true;
        });
    }

    private function orderExists(string $sourceOrderId): bool
    {
        return Order::query()
            ->where('source', OrderSource::ESHOP)
            ->where('source_order_id', $sourceOrderId)
            ->exists();
    }

    private function markProcessed(LegacyOrderData $data): void
    {
        IntegrationEvent::query()->updateOrCreate(
            [
                'integration' => self::INTEGRATION,
                'external_event_id' => 'order:'.$data->sourceOrderId,
            ],
            [
                'type' => 'ORDER_IMPORT',
                'payload_hash' => $data->payloadHash,
                'payload' => null,
                'status' => 'PROCESSED',
                'attempts' => 1,
                'error' => null,
                'processed_at' => now(),
            ],
        );
    }

    private function advanceCheckpoint(string $sourceOrderId): void
    {
        IntegrationCheckpoint::query()->updateOrCreate(
            ['integration' => self::INTEGRATION],
            [
                'cursor' => $sourceOrderId,
                'last_processed_id' => $sourceOrderId,
                'last_processed_at' => now(),
                'metadata' => ['source' => OrderSource::ESHOP->value],
            ],
        );
    }

    private function recordFailure(stdClass $row, Throwable $exception): void
    {
        $sourceId = $this->safeSourceId($row);
        $event = IntegrationEvent::query()->firstOrNew([
            'integration' => self::INTEGRATION,
            'external_event_id' => $sourceId === null ? 'row:'.$this->payloadHash($row) : 'order:'.$sourceId,
        ]);
        $event->fill([
            'type' => 'ORDER_IMPORT',
            'payload_hash' => $this->payloadHash($row),
            'payload' => null,
            'status' => 'FAILED',
            'attempts' => ((int) $event->attempts) + 1,
            'error' => $this->safeError($exception),
            'processed_at' => null,
        ])->save();
    }

    private function safeSourceId(stdClass $row): ?string
    {
        $column = $this->mapping->column('source_order_id');
        $value = $column === null ? null : ($row->{$column} ?? null);

        return is_int($value) || (is_string($value) && ctype_digit($value)) ? (string) $value : null;
    }

    private function payloadHash(stdClass $row): string
    {
        $values = [];
        foreach ($this->mapping->configuredColumns() as $semantic => $column) {
            $values[$semantic] = $row->{$column} ?? null;
        }
        ksort($values);

        return hash('sha256', json_encode($values, JSON_THROW_ON_ERROR));
    }

    private function safeError(Throwable $exception): string
    {
        if ($exception instanceof IncompleteLegacyOrderException) {
            return 'INCOMPLETE_ORDER:'.implode(',', $exception->fields);
        }

        if ($exception instanceof LegacyShopConfigurationException) {
            return 'CONFIGURATION_ERROR';
        }

        return 'IMPORT_ROLLBACK:'.class_basename($exception);
    }
}
