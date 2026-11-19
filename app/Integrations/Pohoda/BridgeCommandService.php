<?php

namespace App\Integrations\Pohoda;

use App\Application\Audit\AuditLogger;
use App\Exceptions\WorkflowConflictException;
use App\Models\BridgeCommand;
use App\Models\BridgeCommandResult;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class BridgeCommandService
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function claim(string $connectorId, int $limit): array
    {
        Validator::make(['connector_id' => $connectorId, 'limit' => $limit], [
            'connector_id' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9._-]+$/'],
            'limit' => ['required', 'integer', 'min:1', 'max:100'],
        ])->validate();
        $allowed = config('integrations.pohoda_bridge.allowed_command_types', []);
        if ($allowed === []) {
            return [];
        }
        $claimed = [];
        while (count($claimed) < $limit) {
            $command = DB::transaction(function () use ($connectorId, $allowed): ?BridgeCommand {
                $now = now();
                $command = BridgeCommand::query()
                    ->whereIn('type', $allowed)
                    ->where(function ($query) use ($now): void {
                        $query->where(function ($pending) use ($now): void {
                            $pending->where('status', BridgeCommand::PENDING)->where('available_at', '<=', $now);
                        })->orWhere(function ($expired) use ($now): void {
                            $expired->where('status', BridgeCommand::PROCESSING)->where('lease_until', '<=', $now);
                        });
                    })
                    ->orderBy('id')->lockForUpdate()->first();
                if ($command === null) {
                    return null;
                }
                if (! $this->safePayload($command->payload)) {
                    $command->forceFill([
                        'status' => BridgeCommand::FAILED,
                        'last_error_code' => 'UNSAFE_COMMAND_PAYLOAD',
                        'completed_at' => $now,
                        'lease_until' => null,
                        'lease_token' => null,
                    ])->save();
                    $this->auditLogger->record('POHODA_BRIDGE_COMMAND_REJECTED', $command, [], [
                        'command_id' => $command->command_id, 'type' => $command->type, 'code' => 'UNSAFE_COMMAND_PAYLOAD',
                    ]);

                    return $command->setAttribute('skip_response', true);
                }
                $command->forceFill([
                    'status' => BridgeCommand::PROCESSING,
                    'attempts' => $command->attempts + 1,
                    'lease_until' => $now->copy()->addMinutes(max(1, (int) config('integrations.pohoda_bridge.command_lease_minutes', 5))),
                    'lease_token' => (string) Str::uuid(),
                    'claimed_by' => $connectorId,
                    'last_error_code' => null,
                ])->save();
                $this->auditLogger->record('POHODA_BRIDGE_COMMAND_CLAIMED', $command, [], [
                    'command_id' => $command->command_id, 'type' => $command->type,
                    'connector_id' => $connectorId, 'attempts' => $command->attempts,
                ]);

                return $command;
            }, 3);
            if ($command === null) {
                break;
            }
            if ($command->getAttribute('skip_response')) {
                continue;
            }
            $claimed[] = [
                'command_id' => $command->command_id,
                'type' => $command->type,
                'payload' => $command->payload,
                'lease_token' => $command->lease_token,
                'lease_expires_at' => $command->lease_until?->utc()->toIso8601String(),
            ];
        }

        return $claimed;
    }

    public function recordResult(BridgeCommand $command, array $input): array
    {
        if (array_diff(array_keys($input), ['result_id', 'lease_token', 'status', 'external_code', 'completed_at', 'metadata']) !== []) {
            throw ValidationException::withMessages(['payload' => 'Unsupported result fields.']);
        }
        $data = Validator::make($input, [
            'result_id' => ['required', 'uuid'],
            'lease_token' => ['required', 'uuid'],
            'status' => ['required', 'in:SUCCESS,ERROR'],
            'external_code' => ['nullable', 'string', 'max:64', 'regex:/^[A-Z0-9_.-]+$/'],
            'completed_at' => ['required', 'date'],
            'metadata' => ['nullable', 'array'],
            'metadata.processed_count' => ['nullable', 'integer', 'min:0'],
            'metadata.duration_ms' => ['nullable', 'integer', 'min:0'],
            'metadata.message_code' => ['nullable', 'string', 'max:64', 'regex:/^[A-Z0-9_.-]+$/'],
        ])->validate();
        if (array_diff(array_keys($data['metadata'] ?? []), ['processed_count', 'duration_ms', 'message_code']) !== []) {
            throw ValidationException::withMessages(['metadata' => 'Unsupported result metadata.']);
        }
        $completedAt = CarbonImmutable::parse($data['completed_at']);
        if ($completedAt->utcOffset() !== 0) {
            throw ValidationException::withMessages(['completed_at' => 'The completion timestamp must use UTC.']);
        }
        $existing = BridgeCommandResult::query()->where('result_id', $data['result_id'])->first();
        if ($existing !== null) {
            if (! $this->sameResult($existing, $command, $data)) {
                throw new WorkflowConflictException('The result_id belongs to another command.');
            }

            return ['result_id' => $existing->result_id, 'status' => 'ACCEPTED', 'idempotent_replay' => true];
        }

        return DB::transaction(function () use ($command, $data): array {
            $locked = BridgeCommand::query()->whereKey($command->id)->lockForUpdate()->firstOrFail();
            $existing = BridgeCommandResult::query()->where('result_id', $data['result_id'])->first();
            if ($existing !== null) {
                if (! $this->sameResult($existing, $locked, $data)) {
                    throw new WorkflowConflictException('The result_id belongs to another command.');
                }

                return ['result_id' => $existing->result_id, 'status' => 'ACCEPTED', 'idempotent_replay' => true];
            }
            if ($locked->status !== BridgeCommand::PROCESSING
                || $locked->lease_until === null || $locked->lease_until->isPast()
                || ! is_string($locked->lease_token) || ! hash_equals($locked->lease_token, $data['lease_token'])) {
                throw new WorkflowConflictException('The command lease is not active.');
            }
            $completedAt = CarbonImmutable::parse($data['completed_at'])->setTimezone(config('app.timezone'));
            $result = BridgeCommandResult::query()->create([
                'result_id' => $data['result_id'],
                'bridge_command_id' => $locked->id,
                'status' => $data['status'],
                'external_code' => $data['external_code'] ?? null,
                'metadata' => $data['metadata'] ?? null,
                'completed_at' => $completedAt,
                'received_at' => now(),
            ]);
            $locked->forceFill([
                'status' => $data['status'] === 'SUCCESS' ? BridgeCommand::SUCCEEDED : BridgeCommand::FAILED,
                'last_error_code' => $data['status'] === 'ERROR' ? ($data['external_code'] ?? 'EXTERNAL_ERROR') : null,
                'completed_at' => $completedAt,
                'lease_until' => null,
                'lease_token' => null,
            ])->save();
            $this->auditLogger->record('POHODA_BRIDGE_COMMAND_RESULT', $locked, [], [
                'command_id' => $locked->command_id,
                'result_id' => $result->result_id,
                'status' => $result->status,
                'external_code' => $result->external_code,
            ]);

            return ['result_id' => $result->result_id, 'status' => 'ACCEPTED', 'idempotent_replay' => false];
        }, 3);
    }

    private function sameResult(BridgeCommandResult $result, BridgeCommand $command, array $data): bool
    {
        return $result->bridge_command_id === $command->id
            && $result->status === $data['status']
            && $result->external_code === ($data['external_code'] ?? null)
            && ($result->metadata ?? []) === ($data['metadata'] ?? [])
            && $result->completed_at?->equalTo(CarbonImmutable::parse($data['completed_at'])->setTimezone(config('app.timezone')));
    }

    private function safePayload(array $payload): bool
    {
        foreach ($payload as $key => $value) {
            if (in_array(strtolower((string) $key), ['sql', 'query', 'statement', 'connection', 'password', 'secret', 'stack_trace'], true)) {
                return false;
            }
            if (is_array($value) && ! $this->safePayload($value)) {
                return false;
            }
            if (! is_array($value) && ! is_scalar($value) && $value !== null) {
                return false;
            }
        }

        return true;
    }
}
