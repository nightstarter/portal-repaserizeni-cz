<?php

namespace App\Integrations\LegacyShop;

use App\Contracts\CreatesInitialProcessThreads;
use App\Enums\OrderSource;
use App\Models\IntegrationCheckpoint;
use App\Models\IntegrationEvent;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Throwable;

class LegacyShopImportService
{
    public const INTEGRATION = 'LEGACY_SHOP_ORDERS';

    public function __construct(
        private readonly LegacyShopMapping $mapping,
        private readonly PrestaShop14OrderReader $reader,
        private readonly LegacyOrderMapper $mapper,
        private readonly CreatesInitialProcessThreads $threadCreator,
    ) {}

    public function run(bool $dryRun = true, ?int $limit = null, ?int $sourceOrderId = null): LegacyImportResult
    {
        if (! $dryRun && ! config('integrations.legacy_shop.enabled')) {
            throw new LegacyShopConfigurationException('Legacy shop import is disabled.');
        }

        if (! $dryRun) {
            $this->mapping->assertImportReady();
        }
        $limit ??= (int) config('integrations.legacy_shop.batch_size', 100);
        if ($sourceOrderId !== null) {
            $candidate = $this->reader->readOne($sourceOrderId);
            if ($candidate === null) {
                throw new LegacyShopConfigurationException('Requested legacy order was not found.');
            }
            $rows = [$candidate];
        } else {
            $boundary = LegacyImportBoundary::fromConfig();
            $checkpoint = IntegrationCheckpoint::query()->where('integration', self::INTEGRATION)->first();
            $rows = $this->reader->read($boundary, $checkpoint?->last_processed_id, $limit);
        }
        $result = new LegacyImportResult($dryRun);

        foreach ($rows as $row) {
            $result->scanned++;

            try {
                $data = $this->mapper->map($row);
                $warnings = $this->warnings($row);

                if ($dryRun) {
                    $result->addValidation($row, [], $warnings);
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
            } catch (IncompleteLegacyOrderException $exception) {
                $result->errors++;
                $result->addValidation($row, $exception->fields, $this->warnings($row));

                if (! $dryRun) {
                    $this->recordFailure($row, $exception);
                }

                if (! $dryRun) {
                    break;
                }
            } catch (Throwable $exception) {
                $result->errors++;
                $result->addValidation($row, ['UNEXPECTED_VALIDATION_FAILURE'], $this->warnings($row));
                if (! $dryRun) {
                    $this->recordFailure($row, $exception);
                    break;
                }
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

    private function recordFailure(PrestaShop14OrderCandidate $row, Throwable $exception): void
    {
        $event = IntegrationEvent::query()->firstOrNew([
            'integration' => self::INTEGRATION,
            'external_event_id' => 'order:'.$row->sourceOrderId,
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

    private function payloadHash(PrestaShop14OrderCandidate $row): string
    {
        return hash('sha256', json_encode([
            'source_order_id' => $row->sourceOrderId,
            'external_order_no' => $row->externalOrderNo,
            'product_code' => $row->productCode,
            'latest_state_id' => $row->latestStateId,
        ], JSON_THROW_ON_ERROR));
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

    /** @return array<int, string> */
    private function warnings(PrestaShop14OrderCandidate $candidate): array
    {
        $warnings = $candidate->warnings;
        if (! $this->mapping->verified()) {
            $warnings[] = 'MAPPING_NOT_VERIFIED';
        }

        return array_values(array_unique($warnings));
    }
}
