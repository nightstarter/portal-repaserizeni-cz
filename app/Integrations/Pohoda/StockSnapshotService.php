<?php

namespace App\Integrations\Pohoda;

use App\Application\Audit\AuditLogger;
use App\Exceptions\WorkflowConflictException;
use App\Models\LegacyProductConfiguration;
use App\Models\StockSnapshotBatch;
use App\Models\StockSnapshotItem;
use App\Models\StockSnapshotRun;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class StockSnapshotService
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function ingest(Request $request): array
    {
        $input = $request->json()->all();
        if (array_diff(array_keys($input), [
            'snapshot_run_id', 'batch_id', 'chunk_index', 'chunk_count', 'final_chunk',
            'source_generated_at', 'warehouse_code', 'items',
        ]) !== []) {
            throw ValidationException::withMessages(['payload' => 'Unsupported snapshot fields.']);
        }
        $data = Validator::make($input, [
            'batch_id' => ['required', 'uuid'],
            'snapshot_run_id' => ['nullable', 'uuid'],
            'chunk_index' => ['nullable', 'integer', 'min:0'],
            'chunk_count' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'final_chunk' => ['nullable', 'boolean'],
            'source_generated_at' => ['required', 'date'],
            'warehouse_code' => ['required', 'string', 'max:32', 'regex:/^[A-Z0-9_-]+$/'],
            'items' => ['present', 'array', 'max:'.max(1, (int) config('integrations.pohoda_bridge.max_batch_items', 500))],
        ])->validate();
        $chunkFields = collect(['snapshot_run_id', 'chunk_index', 'chunk_count', 'final_chunk'])
            ->filter(fn (string $field): bool => array_key_exists($field, $input));
        if (! in_array($chunkFields->count(), [0, 4], true)) {
            throw ValidationException::withMessages(['snapshot_run_id' => 'All chunk metadata fields are required together.']);
        }
        $runId = (string) ($data['snapshot_run_id'] ?? $data['batch_id']);
        $chunkIndex = (int) ($data['chunk_index'] ?? 0);
        $chunkCount = (int) ($data['chunk_count'] ?? 1);
        $finalChunk = (bool) ($data['final_chunk'] ?? true);
        if ($chunkIndex >= $chunkCount || $finalChunk !== ($chunkIndex === $chunkCount - 1)) {
            throw ValidationException::withMessages(['chunk_index' => 'Chunk position or final marker is inconsistent.']);
        }
        $sourceAt = CarbonImmutable::parse($data['source_generated_at']);
        if ($sourceAt->utcOffset() !== 0) {
            throw ValidationException::withMessages(['source_generated_at' => 'The source timestamp must use UTC.']);
        }
        $payloadHash = hash('sha256', $request->getContent());
        $this->expireIncompleteRuns();
        $existing = StockSnapshotBatch::query()->where('batch_id', $data['batch_id'])->first();
        if ($existing !== null) {
            if (! hash_equals($existing->payload_hash, $payloadHash)) {
                throw new WorkflowConflictException('The batch_id was reused with another payload.');
            }

            return $this->response($existing->load(['items', 'run']), true);
        }

        $known = LegacyProductConfiguration::query()
            ->whereIn('product_reference', collect($data['items'])->pluck('product_reference')->filter()->all())
            ->get()->keyBy('product_reference');
        $seen = [];
        $rows = [];
        foreach (array_values($data['items']) as $index => $item) {
            $fingerprint = hash('sha256', json_encode($item, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            $itemData = is_array($item) ? $item : [];
            $validator = Validator::make($itemData, [
                'product_reference' => ['required', 'string', 'max:128', 'regex:/^[A-Za-z0-9._\/-]+$/'],
                'available_quantity' => ['required', 'integer', 'min:0', 'max:2147483647'],
                'reserved_quantity' => ['nullable', 'integer', 'min:0', 'max:2147483647'],
                'source_row_version' => ['required', 'string', 'max:128'],
            ]);
            $reference = is_array($item) && is_string($item['product_reference'] ?? null)
                ? mb_substr($item['product_reference'], 0, 128) : null;
            $code = null;
            if (array_diff(array_keys($itemData), ['product_reference', 'available_quantity', 'reserved_quantity', 'source_row_version']) !== []
                || $validator->fails()) {
                $code = 'INVALID_ITEM';
            } elseif (isset($seen[$reference])) {
                $code = 'DUPLICATE_PRODUCT_REFERENCE';
            } elseif (! $known->has($reference)) {
                $code = 'UNKNOWN_PRODUCT_REFERENCE';
            }
            if ($reference !== null) {
                $seen[$reference] = true;
            }
            $valid = $code === null;
            $rows[] = [
                'item_index' => $index,
                'product_reference' => $reference,
                'product_configuration_id' => $valid ? $known->get($reference)->id : null,
                'available_quantity' => $valid ? (int) $item['available_quantity'] : null,
                'reserved_quantity' => $valid && array_key_exists('reserved_quantity', $item) && $item['reserved_quantity'] !== null
                    ? (int) $item['reserved_quantity'] : null,
                'source_row_version' => $valid ? (string) $item['source_row_version'] : null,
                'source_fingerprint' => $fingerprint,
                'accepted' => $valid,
                'rejection_code' => $code,
            ];
        }

        $accepted = collect($rows)->where('accepted', true)->count();
        $rejected = count($rows) - $accepted;
        try {
            $batch = DB::transaction(function () use (
                $request, $data, $sourceAt, $payloadHash, $rows, $accepted, $rejected,
                $runId, $chunkIndex, $chunkCount, $finalChunk,
            ): StockSnapshotBatch {
                $run = StockSnapshotRun::query()->firstOrCreate(
                    ['run_id' => $runId],
                    [
                        'warehouse_code' => $data['warehouse_code'],
                        'source_generated_at' => $sourceAt->setTimezone(config('app.timezone')),
                        'chunk_count' => $chunkCount,
                        'status' => StockSnapshotRun::RECEIVING,
                        'expires_at' => now()->addMinutes(max(1, (int) config('integrations.pohoda_bridge.snapshot_run_timeout_minutes', 30))),
                    ],
                );
                $run = StockSnapshotRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();
                if ($run->status === StockSnapshotRun::FAILED
                    || $run->warehouse_code !== $data['warehouse_code']
                    || ! $run->source_generated_at->equalTo($sourceAt->setTimezone(config('app.timezone')))
                    || $run->chunk_count !== $chunkCount) {
                    throw new WorkflowConflictException('Snapshot run metadata conflict.');
                }
                $existing = StockSnapshotBatch::query()->where('batch_id', $data['batch_id'])->lockForUpdate()->first();
                if ($existing !== null) {
                    if (! hash_equals($existing->payload_hash, $payloadHash)) {
                        throw new WorkflowConflictException('The batch_id was reused with another payload.');
                    }

                    return $existing;
                }
                if (StockSnapshotBatch::query()->where('stock_snapshot_run_id', $run->id)->where('chunk_index', $chunkIndex)->exists()) {
                    throw new WorkflowConflictException('Snapshot chunk position was already used.');
                }
                $existingReferences = StockSnapshotItem::query()
                    ->whereHas('batch', fn ($query) => $query->where('stock_snapshot_run_id', $run->id))
                    ->whereIn('product_reference', collect($rows)->where('accepted', true)->pluck('product_reference'))
                    ->pluck('product_reference')->flip();
                foreach ($rows as &$row) {
                    if ($row['accepted'] && $existingReferences->has($row['product_reference'])) {
                        $row['accepted'] = false;
                        $row['product_configuration_id'] = null;
                        $row['available_quantity'] = null;
                        $row['reserved_quantity'] = null;
                        $row['source_row_version'] = null;
                        $row['rejection_code'] = 'DUPLICATE_PRODUCT_REFERENCE';
                        $accepted--;
                        $rejected++;
                    }
                }
                unset($row);
                $batch = StockSnapshotBatch::query()->create([
                    'stock_snapshot_run_id' => $run->id,
                    'batch_id' => $data['batch_id'],
                    'chunk_index' => $chunkIndex,
                    'chunk_count' => $chunkCount,
                    'final_chunk' => $finalChunk,
                    'key_id' => $request->attributes->get('bridge_key_id'),
                    'warehouse_code' => $data['warehouse_code'],
                    'source_generated_at' => $sourceAt->setTimezone(config('app.timezone')),
                    'received_at' => now(),
                    'status' => $rejected === 0 ? 'COMPLETE' : 'PARTIAL',
                    'accepted_count' => $accepted,
                    'rejected_count' => $rejected,
                    'payload_hash' => $payloadHash,
                ]);
                $batch->items()->createMany($rows);
                $aggregates = StockSnapshotBatch::query()->where('stock_snapshot_run_id', $run->id)
                    ->selectRaw('COUNT(*) chunks, COALESCE(SUM(accepted_count), 0) accepted, COALESCE(SUM(rejected_count), 0) rejected')
                    ->firstOrFail();
                $isComplete = (int) $aggregates->chunks === $run->chunk_count
                    && StockSnapshotBatch::query()->where('stock_snapshot_run_id', $run->id)
                        ->where('chunk_index', $run->chunk_count - 1)->where('final_chunk', true)->exists();
                $run->forceFill([
                    'received_chunks' => (int) $aggregates->chunks,
                    'accepted_count' => (int) $aggregates->accepted,
                    'rejected_count' => (int) $aggregates->rejected,
                    'status' => $isComplete
                        ? ((int) $aggregates->rejected === 0 ? StockSnapshotRun::COMPLETE : StockSnapshotRun::PARTIAL)
                        : StockSnapshotRun::RECEIVING,
                    'completed_at' => $isComplete ? now() : null,
                ])->save();
                $this->auditLogger->record('POHODA_STOCK_SNAPSHOT_RECEIVED', $batch, [], [
                    'snapshot_run_id' => $run->run_id,
                    'batch_id' => $batch->batch_id,
                    'chunk_index' => $batch->chunk_index,
                    'chunk_count' => $batch->chunk_count,
                    'run_status' => $run->status,
                    'warehouse_code' => $batch->warehouse_code,
                    'status' => $batch->status,
                    'accepted_count' => $accepted,
                    'rejected_count' => $rejected,
                ]);

                return $batch;
            }, 3);
        } catch (QueryException $exception) {
            // A concurrent request can win the unique batch_id insert after our
            // initial lookup. The unique constraint remains the authority.
            $batch = StockSnapshotBatch::query()->where('batch_id', $data['batch_id'])->first();
            if ($batch === null) {
                $run = StockSnapshotRun::query()->where('run_id', $runId)->first();
                if ($run !== null && StockSnapshotBatch::query()
                    ->where('stock_snapshot_run_id', $run->id)->where('chunk_index', $chunkIndex)->exists()) {
                    throw new WorkflowConflictException('Snapshot chunk position was already used.');
                }
                throw $exception;
            }
            if (! hash_equals($batch->payload_hash, $payloadHash)) {
                throw new WorkflowConflictException('The batch_id was reused with another payload.');
            }

            return $this->response($batch->load(['items', 'run']), true);
        }

        return $this->response($batch->load(['items', 'run']), false);
    }

    private function response(StockSnapshotBatch $batch, bool $idempotent): array
    {
        return [
            'snapshot_run_id' => $batch->run?->run_id,
            'batch_id' => $batch->batch_id,
            'status' => $batch->status,
            'run_status' => $batch->run?->status,
            'chunk_index' => $batch->chunk_index,
            'chunk_count' => $batch->chunk_count,
            'accepted_count' => $batch->accepted_count,
            'rejected_count' => $batch->rejected_count,
            'idempotent_replay' => $idempotent,
            'items' => $batch->items->sortBy('item_index')->map(fn ($item): array => [
                'index' => $item->item_index,
                'product_reference' => $item->product_reference,
                'status' => $item->accepted ? 'ACCEPTED' : 'REJECTED',
                'code' => $item->rejection_code,
            ])->values()->all(),
        ];
    }

    public function expireIncompleteRuns(): int
    {
        return StockSnapshotRun::query()
            ->where('status', StockSnapshotRun::RECEIVING)
            ->where('expires_at', '<', now())
            ->update(['status' => StockSnapshotRun::FAILED, 'updated_at' => now()]);
    }
}
