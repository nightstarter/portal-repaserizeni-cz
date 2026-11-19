<?php

namespace App\Integrations\Pohoda;

use App\Application\Audit\AuditLogger;
use App\Exceptions\WorkflowConflictException;
use App\Models\LegacyProductConfiguration;
use App\Models\StockSnapshotBatch;
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
        if (array_diff(array_keys($input), ['batch_id', 'source_generated_at', 'warehouse_code', 'items']) !== []) {
            throw ValidationException::withMessages(['payload' => 'Unsupported snapshot fields.']);
        }
        $data = Validator::make($input, [
            'batch_id' => ['required', 'uuid'],
            'source_generated_at' => ['required', 'date'],
            'warehouse_code' => ['required', 'string', 'max:32', 'regex:/^[A-Z0-9_-]+$/'],
            'items' => ['required', 'array', 'min:1', 'max:'.max(1, (int) config('integrations.pohoda_bridge.max_batch_items', 500))],
        ])->validate();
        $sourceAt = CarbonImmutable::parse($data['source_generated_at']);
        if ($sourceAt->utcOffset() !== 0) {
            throw ValidationException::withMessages(['source_generated_at' => 'The source timestamp must use UTC.']);
        }
        $payloadHash = hash('sha256', $request->getContent());
        $existing = StockSnapshotBatch::query()->where('batch_id', $data['batch_id'])->first();
        if ($existing !== null) {
            if (! hash_equals($existing->payload_hash, $payloadHash)) {
                throw new WorkflowConflictException('The batch_id was reused with another payload.');
            }

            return $this->response($existing->load('items'), true);
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
            $batch = DB::transaction(function () use ($request, $data, $sourceAt, $payloadHash, $rows, $accepted, $rejected): StockSnapshotBatch {
                $existing = StockSnapshotBatch::query()->where('batch_id', $data['batch_id'])->lockForUpdate()->first();
                if ($existing !== null) {
                    if (! hash_equals($existing->payload_hash, $payloadHash)) {
                        throw new WorkflowConflictException('The batch_id was reused with another payload.');
                    }

                    return $existing;
                }
                $batch = StockSnapshotBatch::query()->create([
                    'batch_id' => $data['batch_id'],
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
                $this->auditLogger->record('POHODA_STOCK_SNAPSHOT_RECEIVED', $batch, [], [
                    'batch_id' => $batch->batch_id,
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
                throw $exception;
            }
            if (! hash_equals($batch->payload_hash, $payloadHash)) {
                throw new WorkflowConflictException('The batch_id was reused with another payload.');
            }

            return $this->response($batch->load('items'), true);
        }

        return $this->response($batch->load('items'), false);
    }

    private function response(StockSnapshotBatch $batch, bool $idempotent): array
    {
        return [
            'batch_id' => $batch->batch_id,
            'status' => $batch->status,
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
}
