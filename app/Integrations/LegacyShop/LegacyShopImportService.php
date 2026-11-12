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
            $rows = $this->mergeCandidates(
                $this->reader->readPending($this->pendingSourceOrderIds($checkpoint)),
                $this->reader->read($boundary, $checkpoint?->last_processed_id, $limit),
            );
        }
        $result = new LegacyImportResult($dryRun);

        foreach ($rows as $row) {
            $result->scanned++;

            if ($row->approvalValue === 0) {
                $result->pending++;
                $result->addValidation($row, [], $this->warnings($row), $this->validationContext($row));
                if (! $dryRun) {
                    $this->deferPending($row->sourceOrderId);
                }

                continue;
            }

            try {
                $data = $this->mapper->map($row);
                $warnings = $this->warnings($row);

                if ($dryRun) {
                    $result->addValidation($row, [], $warnings, $this->validationContext($row));
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
                $result->addValidation($row, $exception->fields, $this->warnings($row), $this->validationContext($row));

                if (! $dryRun) {
                    $this->recordFailure($row, $exception);
                }

                if (! $dryRun) {
                    break;
                }
            } catch (Throwable $exception) {
                $result->errors++;
                $result->addValidation($row, ['UNEXPECTED_VALIDATION_FAILURE'], $this->warnings($row), $this->validationContext($row));
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
        $this->updateCheckpoint($sourceOrderId, pending: false);
    }

    private function deferPending(string $sourceOrderId): void
    {
        DB::transaction(fn () => $this->updateCheckpoint($sourceOrderId, pending: true));
    }

    private function updateCheckpoint(string $sourceOrderId, bool $pending): void
    {
        $checkpoint = IntegrationCheckpoint::query()
            ->where('integration', self::INTEGRATION)
            ->lockForUpdate()
            ->first() ?? new IntegrationCheckpoint(['integration' => self::INTEGRATION]);
        $metadata = (array) $checkpoint->metadata;
        $pendingIds = array_values(array_unique(array_map('strval', (array) ($metadata['pending_source_order_ids'] ?? []))));
        if ($pending) {
            $pendingIds[] = $sourceOrderId;
            $pendingIds = array_values(array_unique($pendingIds));
        } else {
            $pendingIds = array_values(array_filter($pendingIds, fn (string $id): bool => $id !== $sourceOrderId));
        }
        sort($pendingIds, SORT_NUMERIC);

        $current = filter_var($checkpoint->last_processed_id, FILTER_VALIDATE_INT) === false
            ? 0
            : (int) $checkpoint->last_processed_id;
        $cursor = (string) max($current, (int) $sourceOrderId);
        $checkpoint->fill([
            'cursor' => $cursor,
            'last_processed_id' => $cursor,
            'last_processed_at' => now(),
            'metadata' => [
                'source' => OrderSource::ESHOP->value,
                'pending_source_order_ids' => $pendingIds,
            ],
        ])->save();
    }

    private function recordFailure(PrestaShop14OrderCandidate $row, Throwable $exception): void
    {
        $event = IntegrationEvent::query()->firstOrNew([
            'integration' => self::INTEGRATION,
            'external_event_id' => 'order:'.$row->sourceOrderId,
        ]);
        $event->fill([
            'type' => $exception instanceof IncompleteLegacyOrderException
                && in_array('PRODUCT_CONFIGURATION_REQUIRED', $exception->fields, true)
                    ? 'PRODUCT_CONFIGURATION_REQUIRED'
                    : 'ORDER_IMPORT',
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

    /** @return array<string, mixed> */
    private function validationContext(PrestaShop14OrderCandidate $candidate): array
    {
        $configuration = $this->mapper->productConfiguration($candidate->productCode);
        $configured = $configuration !== null
            && $configuration->verification_status?->value === 'VERIFIED'
            && $configuration->technical_type !== null
            && $configuration->default_routing !== null;

        return [
            'technical_type' => $configured ? $configuration->technical_type->value : null,
            'routing' => $configured ? $configuration->default_routing->value : null,
            'product_configuration_status' => $configured ? 'VERIFIED' : 'PRODUCT_CONFIGURATION_REQUIRED',
            'configuration_queue' => $configured ? null : 'PRODUCT_CONFIGURATION_REQUIRED',
            'configuration_queue_owner' => $configured ? null : 'AUTOKLUBY',
            'stock_decision_status' => $candidate->repairBranch === 'STOCK_REBUILD'
                ? 'PENDING_STOCK_CHECK'
                : 'NOT_APPLICABLE',
        ];
    }

    /** @return array<int, string> */
    private function pendingSourceOrderIds(?IntegrationCheckpoint $checkpoint): array
    {
        return array_values(array_map('strval', (array) ($checkpoint?->metadata['pending_source_order_ids'] ?? [])));
    }

    /**
     * @param  array<int, PrestaShop14OrderCandidate>  ...$candidateSets
     * @return array<int, PrestaShop14OrderCandidate>
     */
    private function mergeCandidates(array ...$candidateSets): array
    {
        $candidates = [];
        foreach ($candidateSets as $set) {
            foreach ($set as $candidate) {
                $candidates[$candidate->sourceOrderId] = $candidate;
            }
        }
        uksort($candidates, fn (string $left, string $right): int => (int) $left <=> (int) $right);

        return array_values($candidates);
    }
}
