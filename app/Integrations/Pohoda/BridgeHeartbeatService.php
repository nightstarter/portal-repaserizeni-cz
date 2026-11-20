<?php

namespace App\Integrations\Pohoda;

use App\Application\Audit\AuditLogger;
use App\Exceptions\WorkflowConflictException;
use App\Models\BridgeHeartbeat;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class BridgeHeartbeatService
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly StockSnapshotService $stockSnapshots,
    ) {}

    public function record(Request $request): array
    {
        $this->stockSnapshots->expireIncompleteRuns();
        $input = $request->json()->all();
        if (array_diff(array_keys($input), [
            'event_id', 'connector_id', 'connector_version', 'occurred_at',
            'last_pohoda_read_at', 'last_snapshot_at', 'last_command_poll_at', 'status', 'error_codes',
        ]) !== []) {
            throw ValidationException::withMessages(['payload' => 'Unsupported heartbeat fields.']);
        }
        $data = Validator::make($input, [
            'event_id' => ['required', 'uuid'],
            'connector_id' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9._-]+$/'],
            'connector_version' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9.+_-]+$/'],
            'occurred_at' => ['required', 'date'],
            'last_pohoda_read_at' => ['nullable', 'date'],
            'last_snapshot_at' => ['nullable', 'date'],
            'last_command_poll_at' => ['nullable', 'date'],
            'status' => ['required', 'in:OK,DEGRADED,ERROR'],
            'error_codes' => ['nullable', 'array', 'max:10'],
            'error_codes.*' => ['string', 'max:64', 'regex:/^[A-Z0-9_.-]+$/'],
        ])->validate();
        foreach (['occurred_at', 'last_pohoda_read_at', 'last_snapshot_at', 'last_command_poll_at'] as $field) {
            if (isset($data[$field]) && CarbonImmutable::parse($data[$field])->utcOffset() !== 0) {
                throw ValidationException::withMessages([$field => 'The timestamp must use UTC.']);
            }
        }
        $hash = hash('sha256', $request->getContent());
        $existing = BridgeHeartbeat::query()->where('event_id', $data['event_id'])->first();
        if ($existing !== null) {
            if (! hash_equals($existing->payload_hash, $hash)) {
                throw new WorkflowConflictException('The event_id was reused with another heartbeat.');
            }

            return ['event_id' => $existing->event_id, 'status' => 'ACCEPTED', 'idempotent_replay' => true];
        }
        try {
            $heartbeat = DB::transaction(function () use ($request, $data, $hash): BridgeHeartbeat {
                $heartbeat = BridgeHeartbeat::query()->create([
                    'event_id' => $data['event_id'],
                    'key_id' => $request->attributes->get('bridge_key_id'),
                    'connector_id' => $data['connector_id'],
                    'connector_version' => $data['connector_version'],
                    'occurred_at' => $this->localTime($data['occurred_at']),
                    'last_pohoda_read_at' => isset($data['last_pohoda_read_at']) ? $this->localTime($data['last_pohoda_read_at']) : null,
                    'last_snapshot_at' => isset($data['last_snapshot_at']) ? $this->localTime($data['last_snapshot_at']) : null,
                    'last_command_poll_at' => isset($data['last_command_poll_at']) ? $this->localTime($data['last_command_poll_at']) : null,
                    'status' => $data['status'],
                    'error_codes' => $data['error_codes'] ?? null,
                    'payload_hash' => $hash,
                    'received_at' => now(),
                ]);
                $this->auditLogger->record('POHODA_BRIDGE_HEARTBEAT', $heartbeat, [], [
                    'event_id' => $heartbeat->event_id,
                    'connector_id' => $heartbeat->connector_id,
                    'connector_version' => $heartbeat->connector_version,
                    'status' => $heartbeat->status,
                ]);

                return $heartbeat;
            }, 3);
        } catch (QueryException $exception) {
            $heartbeat = BridgeHeartbeat::query()->where('event_id', $data['event_id'])->first();
            if ($heartbeat === null) {
                throw $exception;
            }
            if (! hash_equals($heartbeat->payload_hash, $hash)) {
                throw new WorkflowConflictException('The event_id was reused with another heartbeat.');
            }

            return ['event_id' => $heartbeat->event_id, 'status' => 'ACCEPTED', 'idempotent_replay' => true];
        }

        return ['event_id' => $heartbeat->event_id, 'status' => 'ACCEPTED', 'idempotent_replay' => false];
    }

    private function localTime(string $value): CarbonImmutable
    {
        return CarbonImmutable::parse($value)->setTimezone(config('app.timezone'));
    }
}
