<?php

namespace App\Integrations\Pohoda;

use App\Application\Audit\AuditLogger;
use App\Exceptions\DatasetFingerprintMismatchException;
use App\Exceptions\WorkflowConflictException;
use App\Models\StockSnapshotConfirmation;
use App\Models\StockSnapshotRun;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class StockSnapshotConfirmationService
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function confirm(Request $request): array
    {
        try {
            $input = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw ValidationException::withMessages(['payload' => 'Invalid JSON payload.']);
        }
        if (! is_array($input) || array_diff(array_keys($input), [
            'confirmation_id', 'base_snapshot_run_id', 'dataset_fingerprint', 'warehouse_code', 'source_generated_at',
        ]) !== []) {
            throw ValidationException::withMessages(['payload' => 'Unsupported confirmation payload.']);
        }
        $data = Validator::make($input, [
            'confirmation_id' => ['required', 'uuid'],
            'base_snapshot_run_id' => ['required', 'uuid'],
            'dataset_fingerprint' => ['required', 'regex:/^[a-f0-9]{64}$/'],
            'warehouse_code' => ['required', 'string', 'max:32', 'regex:/^[A-Z0-9_-]+$/'],
            'source_generated_at' => ['required', 'date'],
        ])->validate();
        if (! in_array($data['warehouse_code'], $this->allowedWarehouses(), true)) {
            throw ValidationException::withMessages(['warehouse_code' => 'Warehouse is not allowed.']);
        }
        $sourceAt = CarbonImmutable::parse($data['source_generated_at']);
        $existing = StockSnapshotConfirmation::query()->where('confirmation_id', $data['confirmation_id'])->first();
        if ($existing !== null) {
            return $this->existingResponse($existing, $data);
        }
        $tolerance = min(300, max(1, (int) config('integrations.pohoda_bridge.timestamp_tolerance', 300)));
        if ($sourceAt->utcOffset() !== 0 || abs(now()->timestamp - $sourceAt->timestamp) > $tolerance) {
            throw ValidationException::withMessages(['source_generated_at' => 'Confirmation timestamp is outside the allowed UTC window.']);
        }
        try {
            $confirmation = DB::transaction(function () use ($request, $data, $sourceAt): StockSnapshotConfirmation {
                $base = StockSnapshotRun::query()->where('run_id', $data['base_snapshot_run_id'])
                    ->whereIn('status', [StockSnapshotRun::COMPLETE, StockSnapshotRun::PARTIAL])
                    ->lockForUpdate()->first();
                $latest = StockSnapshotRun::query()->where('warehouse_code', $data['warehouse_code'])
                    ->whereIn('status', [StockSnapshotRun::COMPLETE, StockSnapshotRun::PARTIAL])
                    ->orderByDesc('source_generated_at')->orderByDesc('id')->lockForUpdate()->first();
                if ($base === null || $latest === null || $base->id !== $latest->id
                    || $base->warehouse_code !== $data['warehouse_code']
                    || $base->dataset_fingerprint === null
                    || ! hash_equals($base->dataset_fingerprint, $data['dataset_fingerprint'])) {
                    throw new DatasetFingerprintMismatchException;
                }
                $confirmation = StockSnapshotConfirmation::query()->create([
                    'confirmation_id' => $data['confirmation_id'],
                    'stock_snapshot_run_id' => $base->id,
                    'key_id' => (string) $request->attributes->get('bridge_key_id'),
                    'warehouse_code' => $data['warehouse_code'],
                    'dataset_fingerprint' => $data['dataset_fingerprint'],
                    'source_generated_at' => $sourceAt->setTimezone(config('app.timezone')),
                    'received_at' => now(),
                ]);
                $this->auditLogger->record('POHODA_STOCK_SNAPSHOT_UNCHANGED_CONFIRMED', $confirmation, [], [
                    'confirmation_id' => $confirmation->confirmation_id,
                    'base_snapshot_run_id' => $base->run_id,
                    'warehouse_code' => $confirmation->warehouse_code,
                    'base_status' => $base->status,
                ]);

                return $confirmation->setRelation('run', $base);
            }, 3);
        } catch (QueryException $exception) {
            $existing = StockSnapshotConfirmation::query()->where('confirmation_id', $data['confirmation_id'])->first();
            if ($existing === null) {
                throw $exception;
            }

            return $this->existingResponse($existing, $data);
        }

        return $this->response($confirmation, false);
    }

    private function existingResponse(StockSnapshotConfirmation $confirmation, array $data): array
    {
        $run = $confirmation->run;
        if ($run === null
            || $confirmation->warehouse_code !== $data['warehouse_code']
            || $run->run_id !== $data['base_snapshot_run_id']
            || ! hash_equals($confirmation->dataset_fingerprint, $data['dataset_fingerprint'])
            || ! $confirmation->source_generated_at->equalTo(CarbonImmutable::parse($data['source_generated_at'])->setTimezone(config('app.timezone')))) {
            throw new WorkflowConflictException('The confirmation_id was reused with another payload.');
        }

        return $this->response($confirmation, true);
    }

    private function response(StockSnapshotConfirmation $confirmation, bool $idempotent): array
    {
        return [
            'confirmation_id' => $confirmation->confirmation_id,
            'base_snapshot_run_id' => $confirmation->run->run_id,
            'base_status' => $confirmation->run->status,
            'status' => 'UNCHANGED_CONFIRMED',
            'idempotent_replay' => $idempotent,
            'received_at' => $confirmation->received_at->utc()->toIso8601String(),
        ];
    }

    /** @return list<string> */
    private function allowedWarehouses(): array
    {
        $warehouses = config('integrations.pohoda_bridge.warehouses', ['DECIN']);
        if (! is_array($warehouses) || $warehouses === []) {
            throw ValidationException::withMessages(['warehouse_code' => 'Bridge warehouse scope is not configured safely.']);
        }
        foreach ($warehouses as $warehouse) {
            if (! is_string($warehouse) || $warehouse === '' || $warehouse !== trim($warehouse)
                || ! preg_match('/^[A-Z0-9_-]{1,32}$/D', $warehouse)) {
                throw ValidationException::withMessages(['warehouse_code' => 'Bridge warehouse scope is not configured safely.']);
            }
        }

        return array_values(array_unique($warehouses));
    }
}
