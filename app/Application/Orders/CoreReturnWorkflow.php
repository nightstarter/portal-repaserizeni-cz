<?php

namespace App\Application\Orders;

use App\Application\Audit\AuditLogger;
use App\Enums\CoreInspectionPieceResult;
use App\Enums\CoreReturnAction;
use App\Enums\CoreReturnState;
use App\Enums\CoreTransportCondition;
use App\Enums\FinanceEntryStatus;
use App\Enums\FinanceEntryType;
use App\Enums\OrganizationCode;
use App\Enums\ProcessThreadType;
use App\Enums\ResponsibleParty;
use App\Exceptions\WorkflowConflictException;
use App\Models\CoreReturnInspection;
use App\Models\FinanceEntry;
use App\Models\Item;
use App\Models\Order;
use App\Models\ProcessThread;
use App\Models\ThreadEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class CoreReturnWorkflow
{
    public function __construct(
        private readonly CoreReturnActionGuard $guard,
        private readonly CoreReturnStateMachine $stateMachine,
        private readonly AuditLogger $auditLogger,
        private readonly OrderCompletionService $completion,
    ) {}

    public function execute(Order $order, CoreReturnCommand $command): ThreadEvent
    {
        $this->validateEnvelope($command);

        return DB::transaction(function () use ($order, $command): ThreadEvent {
            $lockedOrder = Order::query()->lockForUpdate()->findOrFail($order->id);
            $thread = ProcessThread::query()
                ->where('order_id', $lockedOrder->id)
                ->where('type', ProcessThreadType::CORE_RETURN)
                ->lockForUpdate()
                ->sole();
            $override = $this->guard->assertMayPerform($command->actor, $command);
            $hash = $this->requestHash($command);
            $existing = $thread->events()->where('idempotency_key', $command->idempotencyKey)->first();
            if ($existing !== null) {
                if ($existing->request_hash !== $hash) {
                    throw new WorkflowConflictException(__('app.workflow_errors.idempotency_reused'));
                }

                return $existing;
            }
            if ($command->expectedLockVersion !== $thread->lock_version) {
                throw new WorkflowConflictException(__('app.workflow_errors.version_conflict'));
            }

            $from = CoreReturnState::from($thread->state);
            $to = $this->isReferenceAction($command->action)
                ? $from
                : $this->stateMachine->nextState($from, $command->action);
            $metadata = ['admin_override' => $override];
            $inspection = null;
            $finance = [];

            if ($command->action === CoreReturnAction::CONFIRM_CORE_RECEIPT_AK) {
                [$inspection, $metadata] = $this->createInspection($lockedOrder, $thread, $command, $metadata);
            } elseif ($this->isReferenceAction($command->action)) {
                $finance = $this->prepareReferenceFinance($thread, $command);
                $metadata += ['finance' => $finance];
            } elseif (in_array($command->action, [
                CoreReturnAction::APPROVE_FULL_DEPOSIT_RETURN,
                CoreReturnAction::APPROVE_REDUCED_DEPOSIT_RETURN,
                CoreReturnAction::CONFIRM_NO_SUPPLIER_DEPOSIT,
            ], true)) {
                $finance = $this->prepareSettlementFinance($thread, $command);
                $metadata += ['finance' => $finance];
            }

            $organization = match ($to) {
                CoreReturnState::CORE_RECEIVED_AK => OrganizationCode::AUTOKLUBY,
                CoreReturnState::SENT_TO_PL, CoreReturnState::CORE_INSPECTION => OrganizationCode::POLAND,
                default => null,
            };
            $organizationId = $organization === null ? null : DB::table('organizations')->where('code', $organization->value)->value('id');
            $completed = $to->completed();
            $updated = ProcessThread::query()->whereKey($thread->id)->where('lock_version', $thread->lock_version)->update([
                'state' => $to->value,
                'responsible_party' => $completed ? ResponsibleParty::SYSTEM->value : match ($organization) {
                    OrganizationCode::POLAND => ResponsibleParty::POLAND->value,
                    OrganizationCode::AUTOKLUBY => ResponsibleParty::AUTOKLUBY->value,
                    default => $thread->responsible_party->value,
                },
                'responsible_organization_id' => $organizationId,
                'completed_at' => $completed ? now() : null,
                'lock_version' => $thread->lock_version + 1,
                'updated_at' => now(),
            ]);
            if ($updated !== 1) {
                throw new WorkflowConflictException(__('app.workflow_errors.version_conflict'));
            }
            $thread->refresh();
            $this->completion->refresh($lockedOrder);

            $reason = $override
                ? trim((string) ($command->data['override_reason'] ?? $command->data['reason'] ?? ''))
                : ($finance['reason'] ?? null);
            $event = $thread->events()->create([
                'order_id' => $lockedOrder->id,
                'action' => $command->action->value,
                'idempotency_key' => $command->idempotencyKey,
                'request_hash' => $hash,
                'from_state' => $from->value,
                'to_state' => $to->value,
                'actor_type' => $command->actor->organization->code->value,
                'actor_user_id' => $command->actor->id,
                'actor_organization_id' => $command->actor->organization_id,
                'responsible_organization_id' => $organizationId,
                'reason' => $reason,
                'metadata' => $metadata,
            ]);
            $audit = $this->auditLogger->record(
                $override ? 'CORE_RETURN_ADMIN_OVERRIDE' : 'CORE_RETURN_ACTION',
                $thread,
                ['state' => $from->value, 'lock_version' => $thread->lock_version - 1],
                ['state' => $to->value, 'lock_version' => $thread->lock_version, 'thread_event_id' => $event->id, 'metadata' => $metadata],
                $reason,
                $command->actor,
            );
            $this->persistFinance($lockedOrder, $thread, $event, $audit->id, $command, $hash, $finance);

            if ($inspection !== null) {
                Item::query()->whereKey($inspection->item_id)->update(['current_location' => OrganizationCode::AUTOKLUBY->value]);
            }

            return $event;
        }, 3);
    }

    private function createInspection(Order $order, ProcessThread $thread, CoreReturnCommand $command, array $metadata): array
    {
        $data = Validator::make($command->data, [
            'piece_result' => ['required', Rule::enum(CoreInspectionPieceResult::class)],
            'transport_condition' => ['required', Rule::enum(CoreTransportCondition::class)],
            'accident' => ['required', 'boolean'],
            'disassembled' => ['required', 'boolean'],
            'corrosion' => ['required', 'boolean'],
            'axial_rods' => ['required', 'boolean'],
            'note' => ['nullable', 'string', 'max:5000'],
        ])->validate();
        $item = Item::query()->where('order_id', $order->id)->where('role', 'CORE_RETURN')->lockForUpdate()->sole();
        $inspection = CoreReturnInspection::query()->create($data + [
            'order_id' => $order->id,
            'process_thread_id' => $thread->id,
            'item_id' => $item->id,
            'actor_user_id' => $command->actor->id,
            'actor_organization_id' => $command->actor->organization_id,
        ]);

        return [$inspection, $metadata + ['inspection_id' => $inspection->id, 'piece_result' => $data['piece_result']]];
    }

    private function prepareReferenceFinance(ProcessThread $thread, CoreReturnCommand $command): array
    {
        if (array_key_exists('source', $command->data) && $command->data['source'] === 'CUSTOMER_DEPOSIT_SNAPSHOT') {
            throw ValidationException::withMessages(['source' => 'CUSTOMER_DEPOSIT_SNAPSHOT cannot be used as supplier finance.']);
        }
        $serverDerivedAmount = in_array($command->action, [CoreReturnAction::RECORD_NO_SUPPLIER_DEPOSIT, CoreReturnAction::REVERSE_FINANCE_ENTRY], true);
        $isCorrection = in_array($command->action, [CoreReturnAction::RECORD_FINANCE_CORRECTION, CoreReturnAction::REVERSE_FINANCE_ENTRY], true);
        $data = Validator::make($command->data, [
            'amount' => $serverDerivedAmount ? ['prohibited'] : ['required', 'string'],
            'currency' => ['required', 'string', 'regex:/^[A-Z]{3}$/'],
            'reason' => ['required', 'string', 'max:2000'],
            'entry_id' => $isCorrection ? ['required', 'integer', 'min:1'] : ['prohibited'],
        ])->validate();
        $type = match ($command->action) {
            CoreReturnAction::RECORD_SUPPLIER_PURCHASE_PRICE => FinanceEntryType::SUPPLIER_PURCHASE_PRICE,
            CoreReturnAction::RECORD_SUPPLIER_DEPOSIT_ORIGINAL => FinanceEntryType::SUPPLIER_DEPOSIT_ORIGINAL,
            CoreReturnAction::RECORD_NO_SUPPLIER_DEPOSIT => FinanceEntryType::SUPPLIER_DEPOSIT_NOT_CHARGED,
            CoreReturnAction::RECORD_FINANCE_CORRECTION => FinanceEntryType::CORRECTION,
            CoreReturnAction::REVERSE_FINANCE_ENTRY => FinanceEntryType::REVERSAL,
            default => throw new \LogicException,
        };
        if (in_array($type, [FinanceEntryType::SUPPLIER_DEPOSIT_ORIGINAL, FinanceEntryType::SUPPLIER_DEPOSIT_NOT_CHARGED], true)
            && $thread->financeEntries()->whereIn('type', [FinanceEntryType::SUPPLIER_DEPOSIT_ORIGINAL, FinanceEntryType::SUPPLIER_DEPOSIT_NOT_CHARGED])->exists()) {
            throw new WorkflowConflictException('Supplier deposit terms are already recorded; use an AK_ADMIN correction event.');
        }

        $corrects = null;
        if (in_array($type, [FinanceEntryType::CORRECTION, FinanceEntryType::REVERSAL], true)) {
            $corrects = FinanceEntry::query()->where('process_thread_id', $thread->id)->find($data['entry_id']);
            if ($corrects === null) {
                throw ValidationException::withMessages(['entry_id' => 'A finance entry from this CORE_RETURN is required.']);
            }
            if ($data['currency'] !== $corrects->currency) {
                throw ValidationException::withMessages(['currency' => 'Correction currency must match the corrected entry.']);
            }
        }

        return [
            'entries' => [[
                'type' => $type,
                'amount' => $type === FinanceEntryType::SUPPLIER_DEPOSIT_NOT_CHARGED
                    ? '0.00'
                    : ($type === FinanceEntryType::REVERSAL ? $this->negatedAmount($corrects->amount) : $this->amount($data['amount'], false)),
                'currency' => $data['currency'],
                'status' => $type === FinanceEntryType::REVERSAL ? FinanceEntryStatus::REVERSED : FinanceEntryStatus::CONFIRMED,
                'reason' => trim($data['reason']),
                'corrects_entry_id' => $corrects?->id,
            ]],
            'reason' => trim($data['reason']),
        ];
    }

    private function prepareSettlementFinance(ProcessThread $thread, CoreReturnCommand $command): array
    {
        if ($command->action === CoreReturnAction::CONFIRM_NO_SUPPLIER_DEPOSIT) {
            $evidence = $thread->financeEntries()->where('type', FinanceEntryType::SUPPLIER_DEPOSIT_NOT_CHARGED)->latest('id')->first();
            if ($evidence === null) {
                throw new WorkflowConflictException('Explicit supplier-deposit-not-charged evidence is required.');
            }

            return ['reason' => $evidence->reason, 'entries' => [[
                'type' => FinanceEntryType::SUPPLIER_DEPOSIT_SETTLED_NO_DEPOSIT,
                'amount' => '0.00', 'currency' => $evidence->currency,
                'status' => FinanceEntryStatus::SETTLED,
                'reason' => $evidence->reason,
            ]]];
        }
        $original = $thread->financeEntries()->where('type', FinanceEntryType::SUPPLIER_DEPOSIT_ORIGINAL)->latest('id')->first();
        if ($original === null || $original->status !== FinanceEntryStatus::CONFIRMED) {
            throw new WorkflowConflictException('Confirmed SUPPLIER_DEPOSIT_ORIGINAL is required.');
        }
        if ($command->action === CoreReturnAction::APPROVE_FULL_DEPOSIT_RETURN) {
            if (array_key_exists('amount', $command->data) || array_key_exists('approved_return_amount', $command->data)) {
                throw ValidationException::withMessages(['amount' => 'Full return amount is derived by the server.']);
            }

            return ['entries' => [[
                'type' => FinanceEntryType::SUPPLIER_DEPOSIT_RETURN_APPROVED,
                'amount' => $original->amount, 'currency' => $original->currency,
                'status' => FinanceEntryStatus::APPROVED, 'reason' => null,
            ]]];
        }
        $data = Validator::make($command->data, [
            'approved_return_amount' => ['required', 'string'],
            'currency' => ['required', 'string', 'regex:/^[A-Z]{3}$/'],
            'reason' => ['required', 'string', 'max:2000'],
        ])->validate();
        if (trim($data['reason']) === '') {
            throw ValidationException::withMessages(['reason' => __('validation.required', ['attribute' => 'reason'])]);
        }
        if ($data['currency'] !== $original->currency) {
            throw ValidationException::withMessages(['currency' => 'Currency must equal the original supplier deposit currency.']);
        }
        $approvedMinor = $this->minor($data['approved_return_amount']);
        $originalMinor = $this->minor($original->amount);
        if ($approvedMinor < 0 || $approvedMinor > $originalMinor) {
            throw ValidationException::withMessages(['approved_return_amount' => 'Approved return must be between zero and the original supplier deposit.']);
        }
        if ($approvedMinor === $originalMinor) {
            throw ValidationException::withMessages(['approved_return_amount' => 'Use APPROVE_FULL_DEPOSIT_RETURN when amounts are equal.']);
        }
        $reason = trim($data['reason']);

        return ['reason' => $reason, 'entries' => [[
            'type' => FinanceEntryType::SUPPLIER_DEPOSIT_RETURN_APPROVED,
            'amount' => $this->formatMinor($approvedMinor), 'currency' => $original->currency,
            'status' => FinanceEntryStatus::APPROVED, 'reason' => $reason,
        ], [
            'type' => FinanceEntryType::SUPPLIER_DEPOSIT_REDUCTION,
            'amount' => $this->formatMinor($originalMinor - $approvedMinor), 'currency' => $original->currency,
            'status' => FinanceEntryStatus::APPROVED, 'reason' => $reason,
        ]]];
    }

    private function persistFinance(Order $order, ProcessThread $thread, ThreadEvent $event, int $auditId, CoreReturnCommand $command, string $hash, array $finance): void
    {
        foreach (($finance['entries'] ?? []) as $index => $entry) {
            FinanceEntry::query()->create([
                'order_id' => $order->id, 'process_thread_id' => $thread->id,
                'thread_event_id' => $event->id, 'audit_log_id' => $auditId,
                'type' => $entry['type'], 'amount' => $entry['amount'], 'currency' => $entry['currency'],
                'status' => $entry['status'], 'actor_user_id' => $command->actor->id,
                'actor_organization_id' => $command->actor->organization_id,
                'reason' => $entry['reason'],
                'corrects_entry_id' => $entry['corrects_entry_id'] ?? null,
                'idempotency_key' => $command->idempotencyKey.':'.($index + 1), 'request_hash' => $hash,
            ]);
        }
    }

    private function validateEnvelope(CoreReturnCommand $command): void
    {
        Validator::make(['key' => $command->idempotencyKey, 'version' => $command->expectedLockVersion], [
            'key' => ['required', 'string', 'max:120'], 'version' => ['required', 'integer', 'min:0'],
        ])->validate();
    }

    private function isReferenceAction(CoreReturnAction $action): bool
    {
        return in_array($action, [
            CoreReturnAction::RECORD_SUPPLIER_PURCHASE_PRICE,
            CoreReturnAction::RECORD_SUPPLIER_DEPOSIT_ORIGINAL,
            CoreReturnAction::RECORD_NO_SUPPLIER_DEPOSIT,
            CoreReturnAction::RECORD_FINANCE_CORRECTION,
            CoreReturnAction::REVERSE_FINANCE_ENTRY,
        ], true);
    }

    private function requestHash(CoreReturnCommand $command): string
    {
        $data = $command->data;
        ksort($data);

        return hash('sha256', json_encode(['action' => $command->action->value, 'actor_id' => $command->actor->id, 'data' => $data, 'expected_lock_version' => $command->expectedLockVersion], JSON_THROW_ON_ERROR));
    }

    private function amount(string $amount, bool $allowZero): string
    {
        $minor = $this->minor($amount);
        if ($minor < ($allowZero ? 0 : 1)) {
            throw ValidationException::withMessages(['amount' => 'Amount must be positive.']);
        }

        return $this->formatMinor($minor);
    }

    private function minor(string $amount): int
    {
        if (! preg_match('/^-?(?:0|[1-9][0-9]*)(?:\.[0-9]{1,2})?$/', $amount)) {
            throw ValidationException::withMessages(['amount' => 'Amount must be a decimal with at most two fraction digits.']);
        }
        $negative = str_starts_with($amount, '-');
        $plain = ltrim($amount, '-');
        [$whole, $fraction] = array_pad(explode('.', $plain, 2), 2, '');
        $minor = ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');

        return $negative ? -$minor : $minor;
    }

    private function formatMinor(int $minor): string
    {
        $negative = $minor < 0 ? '-' : '';
        $minor = abs($minor);

        return $negative.intdiv($minor, 100).'.'.str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }

    private function negatedAmount(string $amount): string
    {
        return $this->formatMinor(-$this->minor($amount));
    }
}
