<?php

namespace App\Application\Orders;

use App\Application\Audit\AuditLogger;
use App\Application\Finance\FinancialLedgerService;
use App\Enums\BuybackAction;
use App\Enums\BuybackPriceType;
use App\Enums\BuybackState;
use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Enums\FinanceEntryStatus;
use App\Enums\FinanceEntryType;
use App\Enums\OrganizationCode;
use App\Enums\ProcessThreadType;
use App\Enums\ResponsibleParty;
use App\Enums\ShipmentDirection;
use App\Enums\ShipmentStatus;
use App\Exceptions\WorkflowConflictException;
use App\Models\BuybackPriceQuote;
use App\Models\Document;
use App\Models\Organization;
use App\Models\OutboxEvent;
use App\Models\ProcessThread;
use App\Models\Shipment;
use App\Models\ThreadEvent;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class BuybackWorkflow
{
    public function __construct(
        private readonly BuybackActionGuard $guard,
        private readonly BuybackStateMachine $stateMachine,
        private readonly AuditLogger $auditLogger,
        private readonly FinancialLedgerService $ledger,
    ) {}

    public function execute(ProcessThread $thread, BuybackCommand $command): ThreadEvent
    {
        if ($command->action === BuybackAction::CREATE) {
            throw new WorkflowConflictException('CREATE_BUYBACK is only valid when creating a system buyback order.');
        }
        Validator::make([
            'idempotency_key' => $command->idempotencyKey,
            'expected_lock_version' => $command->expectedLockVersion,
        ], [
            'idempotency_key' => ['required', 'string', 'max:128'],
            'expected_lock_version' => ['required', 'integer', 'min:0'],
        ])->validate();

        return DB::transaction(function () use ($thread, $command): ThreadEvent {
            $locked = ProcessThread::query()->where('type', ProcessThreadType::BUYOUT)->lockForUpdate()->findOrFail($thread->id);
            $this->guard->assertMayPerform($command->actor, $command->action, $locked);
            $hash = $this->requestHash($locked, $command);
            $existing = $locked->events()->where('idempotency_key', $command->idempotencyKey)->first();
            if ($existing !== null) {
                if ($existing->request_hash !== $hash) {
                    throw new WorkflowConflictException('Idempotency key was reused with another request.');
                }

                return $existing;
            }
            if ($command->expectedLockVersion !== $locked->lock_version) {
                throw new WorkflowConflictException('The buyback was changed by another request.');
            }
            if ($locked->completed_at !== null) {
                throw new WorkflowConflictException('A completed buyback is append-only.');
            }
            if (array_key_exists('state', $command->data)) {
                throw ValidationException::withMessages(['state' => 'Direct state selection is forbidden.']);
            }

            $from = BuybackState::from($locked->state);
            $to = $command->action === BuybackAction::SET_INTERNAL_PRICE
                ? $from
                : $this->stateMachine->nextState($from, $command->action);
            $price = $this->priceFor($locked, $command, $hash);
            $this->assertPreconditions($locked, $command);
            [$party, $organization] = $this->responsibilityFor($to);
            $old = $this->snapshot($locked);
            $now = now();
            $updated = ProcessThread::query()->whereKey($locked->id)->where('lock_version', $locked->lock_version)->update([
                'state' => $to->value,
                'responsible_party' => $party->value,
                'responsible_organization_id' => $organization?->id,
                'completed_at' => $to->isTerminal() ? $now : null,
                'lock_version' => $locked->lock_version + 1,
                'updated_at' => $now,
            ]);
            if ($updated !== 1) {
                throw new WorkflowConflictException('The buyback was changed by another request.');
            }
            $locked->refresh();
            $metadata = Arr::only($command->data, ['shipment_id', 'shipment_public_number', 'document_id']) + [
                'price_quote_id' => $price?->id,
                'item_id' => $locked->item_id,
            ];
            if ($command->action === BuybackAction::SUBMIT_PL_OFFER && $price !== null) {
                $metadata['recommended_minor'] = self::recommendedMinor($price->amount_minor);
                $metadata['currency'] = $price->currency;
            }
            $event = $locked->events()->create([
                'order_id' => $locked->order_id,
                'action' => $command->action->value,
                'idempotency_key' => $command->idempotencyKey,
                'request_hash' => $hash,
                'from_state' => $from->value,
                'to_state' => $to->value,
                'actor_type' => $command->actor->organization->code->value,
                'actor_user_id' => $command->actor->id,
                'actor_organization_id' => $command->actor->organization_id,
                'responsible_organization_id' => $organization?->id,
                'reason' => $command->data['reason'] ?? null,
                'metadata' => array_filter($metadata, static fn ($value): bool => $value !== null),
            ]);
            $audit = $this->auditLogger->record('BUYBACK_ACTION', $locked, $old, $this->snapshot($locked) + [
                'action' => $command->action->value,
                'thread_event_id' => $event->id,
                'price_quote_id' => $price?->id,
            ], $command->data['reason'] ?? null, $command->actor);

            if ($command->action === BuybackAction::QUEUE_CUSTOMER_OFFER) {
                $this->createCustomerOutbox($locked, $event);
            }
            if ($command->action === BuybackAction::MARK_PAID_BY_PL) {
                $document = Document::query()->where('process_thread_id', $locked->id)
                    ->where('id', $command->data['document_id'] ?? null)->firstOrFail();
                $this->ledger->append($locked->order, $locked, $event, $audit->id, $command->actor,
                    'buyback-payment:'.$command->idempotencyKey, $hash, [[
                        'type' => FinanceEntryType::BUYBACK_POLAND_PAYMENT,
                        'amount' => $document->amount,
                        'currency' => $document->currency,
                        'status' => FinanceEntryStatus::SETTLED,
                        'reason' => 'BUYBACK_INVOICE_DOCUMENT:'.$document->id,
                    ]]);
            }

            return $event;
        }, 3);
    }

    public static function recommendedMinor(int $polandOfferMinor): int
    {
        return intdiv(($polandOfferMinor * 70) + 50, 100);
    }

    private function priceFor(ProcessThread $thread, BuybackCommand $command, string $hash): ?BuybackPriceQuote
    {
        $type = match ($command->action) {
            BuybackAction::SET_INTERNAL_PRICE => BuybackPriceType::INTERNAL_AK,
            BuybackAction::SUBMIT_PL_OFFER => BuybackPriceType::POLAND_OFFER,
            BuybackAction::APPROVE_FINAL_PRICE => BuybackPriceType::FINAL_CUSTOMER,
            default => null,
        };
        if ($type === null) {
            return null;
        }
        $data = Validator::make($command->data, [
            'amount_minor' => ['required', 'integer', 'min:0', 'max:1000000000000000'],
            'currency' => ['required', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
        ])->validate();
        $version = ((int) BuybackPriceQuote::query()->where('process_thread_id', $thread->id)->where('type', $type)->max('version')) + 1;

        return BuybackPriceQuote::query()->create([
            'order_id' => $thread->order_id,
            'process_thread_id' => $thread->id,
            'type' => $type,
            'amount_minor' => $data['amount_minor'],
            'currency' => $data['currency'],
            'version' => $version,
            'actor_user_id' => $command->actor->id,
            'actor_organization_id' => $command->actor->organization_id,
            'idempotency_key' => $command->idempotencyKey,
            'request_hash' => $hash,
        ]);
    }

    private function assertPreconditions(ProcessThread $thread, BuybackCommand $command): void
    {
        if (in_array($command->action, [BuybackAction::SEND_TO_PL, BuybackAction::CONFIRM_PL_RECEIPT], true)) {
            $shipment = Shipment::query()->whereKey($command->data['shipment_id'] ?? null)
                ->where('direction', ShipmentDirection::AK_TO_PL)
                ->whereHas('shipmentItems', fn ($items) => $items
                    ->where('process_thread_id', $thread->id)
                    ->where('item_id', $thread->item_id))
                ->first();
            $expectedStatus = $command->action === BuybackAction::SEND_TO_PL ? ShipmentStatus::PREPARED : ShipmentStatus::SENT;
            if ($shipment === null || $shipment->status !== $expectedStatus) {
                throw new WorkflowConflictException('Buyback transport must be driven atomically by ShipmentService.');
            }
        }
        if (in_array($command->action, [BuybackAction::CONFIRM_CUSTOMER_OFFER, BuybackAction::QUEUE_CUSTOMER_OFFER], true)
            && ! $thread->buybackPriceQuotes()->where('type', BuybackPriceType::FINAL_CUSTOMER)->exists()) {
            throw new WorkflowConflictException('A manually approved final customer price is required.');
        }
        if ($command->action === BuybackAction::MARK_PAID_BY_PL && empty($command->data['document_id'])) {
            throw ValidationException::withMessages(['document_id' => 'The active invoice to Poland is required.']);
        }
        if ($command->action === BuybackAction::MARK_PAID_BY_PL
            && ! Document::query()->whereKey($command->data['document_id'])
                ->where('process_thread_id', $thread->id)
                ->where('type', DocumentType::BUYBACK_INVOICE_TO_POLAND)
                ->where('status', DocumentStatus::ACTIVE)
                ->where('active_key', 'buyback-invoice:'.$thread->id)
                ->exists()) {
            throw new WorkflowConflictException('Only the active invoice to Poland can be marked paid.');
        }
    }

    private function createCustomerOutbox(ProcessThread $thread, ThreadEvent $event): void
    {
        $order = $thread->order()->with('customer')->firstOrFail();
        $final = $thread->buybackPriceQuotes()->where('type', BuybackPriceType::FINAL_CUSTOMER)->latest('version')->firstOrFail();
        OutboxEvent::query()->firstOrCreate(['deduplication_key' => 'buyback-customer-offer:'.$thread->id], [
            'event_uuid' => (string) Str::uuid(),
            'event_type' => 'BUYBACK_CUSTOMER_OFFER_EMAIL',
            'aggregate_type' => ProcessThread::class,
            'aggregate_id' => $thread->id,
            'payload' => [
                'order_no' => $order->order_no,
                'recipient_email' => $order->customer?->email,
                'amount_minor' => $final->amount_minor,
                'currency' => $final->currency,
                'thread_event_id' => $event->id,
            ],
            'status' => OutboxEvent::PENDING,
            'available_at' => now(),
            'next_attempt_at' => now(),
        ]);
    }

    private function responsibilityFor(BuybackState $state): array
    {
        $party = match ($state) {
            BuybackState::WAITING_FOR_PL_OFFER, BuybackState::SENT_TO_PL, BuybackState::RECEIVED_PL => ResponsibleParty::POLAND,
            BuybackState::OFFER_SENT, BuybackState::CUSTOMER_ACCEPTED => ResponsibleParty::CUSTOMER,
            BuybackState::RETAINED_AK, BuybackState::PAID_BY_PL => ResponsibleParty::SYSTEM,
            default => ResponsibleParty::AUTOKLUBY,
        };
        $organization = in_array($party, [ResponsibleParty::AUTOKLUBY, ResponsibleParty::POLAND], true)
            ? Organization::query()->where('code', $party === ResponsibleParty::AUTOKLUBY ? OrganizationCode::AUTOKLUBY : OrganizationCode::POLAND)->sole()
            : null;

        return [$party, $organization];
    }

    private function requestHash(ProcessThread $thread, BuybackCommand $command): string
    {
        return hash('sha256', json_encode([
            'thread_id' => $thread->id, 'action' => $command->action->value,
            'data' => Arr::sortRecursive($command->data),
        ], JSON_THROW_ON_ERROR));
    }

    private function snapshot(ProcessThread $thread): array
    {
        return Arr::only($thread->toArray(), ['id', 'order_id', 'item_id', 'state', 'responsible_party', 'responsible_organization_id', 'lock_version', 'completed_at']);
    }
}
