<?php

namespace App\Application\Shipments;

use App\Application\Audit\AuditLogger;
use App\Application\Orders\CustomRebuildActionGuard;
use App\Application\Orders\CustomRebuildCommand;
use App\Application\Orders\CustomRebuildWorkflow;
use App\Application\Orders\StockRebuildActionGuard;
use App\Application\Orders\StockRebuildCommand;
use App\Application\Orders\StockRebuildWorkflow;
use App\Enums\CustomRebuildAction;
use App\Enums\ItemRole;
use App\Enums\OrganizationCode;
use App\Enums\ProcessThreadType;
use App\Enums\ShipmentAction;
use App\Enums\ShipmentCarrier;
use App\Enums\ShipmentDirection;
use App\Enums\ShipmentStatus;
use App\Enums\StockRebuildAction;
use App\Enums\UserRole;
use App\Exceptions\WorkflowConflictException;
use App\Models\Item;
use App\Models\Organization;
use App\Models\ProcessThread;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Models\ShipmentItem;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class ShipmentService
{
    public function __construct(
        private readonly CustomRebuildActionGuard $customGuard,
        private readonly StockRebuildActionGuard $stockGuard,
        private readonly CustomRebuildWorkflow $customWorkflow,
        private readonly StockRebuildWorkflow $stockWorkflow,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function create(ShipmentCommand $command): Shipment
    {
        $this->validateCommand($command, false);
        $validated = Validator::make($command->data, [
            'direction' => ['required', Rule::enum(ShipmentDirection::class)],
            'carrier' => ['required', Rule::enum(ShipmentCarrier::class)],
            'tracking' => ['nullable', 'string', 'max:128'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ])->validate();
        $direction = ShipmentDirection::from($validated['direction']);
        $carrier = ShipmentCarrier::from($validated['carrier']);
        $tracking = $this->normalizeTracking($validated['tracking'] ?? null);
        $this->assertDirectionCarrier($direction, $carrier);

        if ($carrier === ShipmentCarrier::SVOZ_AUTOKLUBY && $tracking !== null) {
            throw ValidationException::withMessages(['tracking' => 'SVOZ_AUTOKLUBY nesmí obsahovat tracking.']);
        }

        $override = $command->actor->organization->code !== $direction->sender();
        if ($override && ! ($command->actor->organization->code === OrganizationCode::AUTOKLUBY
            && $command->actor->role === UserRole::AK_ADMIN)) {
            throw new AuthorizationException;
        }
        $reason = trim((string) ($validated['reason'] ?? ''));
        if ($override && $reason === '') {
            throw ValidationException::withMessages(['reason' => 'Administrátorský override vyžaduje důvod.']);
        }
        if (! $override) {
            $this->assertOperationalActor($command->actor, $direction->sender());
        }

        $hash = $this->requestHash(ShipmentAction::CREATE_SHIPMENT, $command);
        if (($existing = $this->existingEvent($command, $hash)) !== null) {
            return $existing->shipment;
        }

        return DB::transaction(function () use ($command, $direction, $carrier, $tracking, $override, $reason, $hash): Shipment {
            if (($existing = $this->existingEvent($command, $hash)) !== null) {
                return $existing->shipment;
            }

            $organizations = Organization::query()
                ->whereIn('code', [$direction->sender(), $direction->recipient()])
                ->get()
                ->keyBy(fn (Organization $organization): string => $organization->code->value);
            $sender = $organizations->get($direction->sender()->value);
            $recipient = $organizations->get($direction->recipient()->value);
            if ($sender === null || $recipient === null) {
                throw new WorkflowConflictException('Required shipment organizations are missing.');
            }

            $shipment = Shipment::query()->create([
                'direction' => $direction,
                'carrier' => $carrier,
                'tracking' => $tracking,
                'status' => ShipmentStatus::PREPARED,
                'sender_organization_id' => $sender->id,
                'recipient_organization_id' => $recipient->id,
                'created_by' => $command->actor->id,
            ]);
            $event = $this->recordEvent(
                $shipment,
                ShipmentAction::CREATE_SHIPMENT,
                $command,
                $hash,
                null,
                ShipmentStatus::PREPARED,
                $override ? $reason : null,
                ['direction' => $direction->value, 'carrier' => $carrier->value, 'admin_override' => $override],
            );
            $this->auditLogger->record(
                $override ? 'SHIPMENT_ADMIN_OVERRIDE_CREATED' : 'SHIPMENT_CREATED',
                $shipment,
                [],
                $this->snapshot($shipment) + ['shipment_event_id' => $event->id],
                $override ? $reason : null,
                $command->actor,
            );

            return $shipment;
        }, 3);
    }

    public function addItem(Shipment $shipment, Item $item, ProcessThread $thread, ShipmentCommand $command): ShipmentEvent
    {
        return $this->preparedItemMutation(
            ShipmentAction::ADD_SHIPMENT_ITEM,
            $shipment,
            $item,
            $thread,
            $command,
            function (Shipment $lockedShipment, Item $lockedItem, ProcessThread $lockedThread) use ($command): void {
                if ($lockedItem->active_shipment_id !== null) {
                    throw new WorkflowConflictException('Item is already reserved by an active shipment.');
                }
                if (ShipmentItem::query()->where('shipment_id', $lockedShipment->id)->where('item_id', $lockedItem->id)->exists()) {
                    throw new WorkflowConflictException('Item is already present in this shipment.');
                }

                $this->assertEligible($lockedShipment, $lockedItem, $lockedThread, $command->actor);
                ShipmentItem::query()->create([
                    'shipment_id' => $lockedShipment->id,
                    'item_id' => $lockedItem->id,
                    'process_thread_id' => $lockedThread->id,
                ]);
                $lockedItem->update(['active_shipment_id' => $lockedShipment->id]);
            },
        );
    }

    public function removeItem(Shipment $shipment, Item $item, ProcessThread $thread, ShipmentCommand $command): ShipmentEvent
    {
        return $this->preparedItemMutation(
            ShipmentAction::REMOVE_SHIPMENT_ITEM,
            $shipment,
            $item,
            $thread,
            $command,
            function (Shipment $lockedShipment, Item $lockedItem, ProcessThread $lockedThread): void {
                $link = ShipmentItem::query()
                    ->where('shipment_id', $lockedShipment->id)
                    ->where('item_id', $lockedItem->id)
                    ->where('process_thread_id', $lockedThread->id)
                    ->lockForUpdate()
                    ->first();
                if ($link === null || $lockedItem->active_shipment_id !== $lockedShipment->id) {
                    throw new WorkflowConflictException('Item is not reserved by this shipment.');
                }

                $link->delete();
                $lockedItem->update(['active_shipment_id' => null]);
            },
        );
    }

    public function send(Shipment $shipment, ShipmentCommand $command): ShipmentEvent
    {
        $this->validateCommand($command);
        $hash = $this->requestHash(ShipmentAction::SEND_SHIPMENT, $command, ['shipment_id' => $shipment->id]);

        return DB::transaction(function () use ($shipment, $command, $hash): ShipmentEvent {
            $locked = Shipment::query()->lockForUpdate()->findOrFail($shipment->id);
            if (($existing = $this->existingEvent($command, $hash, $locked->id)) !== null) {
                return $existing;
            }
            $this->assertExpectedVersion($locked, $command);
            $this->assertOperationalActor($command->actor, $locked->direction->sender());
            if ($locked->status !== ShipmentStatus::PREPARED) {
                throw new WorkflowConflictException('Only a PREPARED shipment can be sent.');
            }

            $tracking = $this->validatedTrackingForSend($locked, $command->data['tracking'] ?? null);
            $entries = $this->lockEntries($locked);
            if ($entries === []) {
                throw ValidationException::withMessages(['items' => 'An empty shipment cannot be sent.']);
            }

            foreach ($entries as [$link, $item, $thread]) {
                if ($item->active_shipment_id !== $locked->id || $link->process_thread_id !== $thread->id) {
                    throw new WorkflowConflictException('Shipment item reservation is inconsistent.');
                }
                $this->assertEligible($locked, $item, $thread, $command->actor);
            }

            foreach ($entries as [, , $thread]) {
                $this->executeDispatch($locked, $thread, $command);
            }

            $from = $locked->status;
            $old = $this->snapshot($locked);
            $this->updateShipment($locked, [
                'status' => ShipmentStatus::SENT->value,
                'tracking' => $tracking,
                'sent_at' => now(),
            ]);
            $metadata = $this->entryMetadata($entries) + ['tracking' => $tracking];
            $event = $this->recordEvent($locked, ShipmentAction::SEND_SHIPMENT, $command, $hash, $from, ShipmentStatus::SENT, null, $metadata);
            $this->auditLogger->record('SHIPMENT_SENT', $locked, $old, $this->snapshot($locked) + [
                'shipment_event_id' => $event->id,
                'items' => $metadata['items'],
            ], null, $command->actor);

            return $event;
        }, 3);
    }

    public function confirmDelivery(Shipment $shipment, ShipmentCommand $command): ShipmentEvent
    {
        $this->validateCommand($command);
        $hash = $this->requestHash(ShipmentAction::CONFIRM_SHIPMENT_DELIVERY, $command, ['shipment_id' => $shipment->id]);

        return DB::transaction(function () use ($shipment, $command, $hash): ShipmentEvent {
            $locked = Shipment::query()->lockForUpdate()->findOrFail($shipment->id);
            if (($existing = $this->existingEvent($command, $hash, $locked->id)) !== null) {
                return $existing;
            }
            $this->assertExpectedVersion($locked, $command);
            $this->assertOperationalActor($command->actor, $locked->direction->recipient());
            if (! in_array($locked->status, [ShipmentStatus::SENT, ShipmentStatus::TRANSPORT_ERROR], true)) {
                throw new WorkflowConflictException('Only a sent or transport-error shipment can be delivered.');
            }

            $entries = $this->lockEntries($locked);
            if ($entries === []) {
                throw new WorkflowConflictException('Shipment has no items.');
            }
            foreach ($entries as [$link, $item, $thread]) {
                if ($item->active_shipment_id !== $locked->id || $link->process_thread_id !== $thread->id) {
                    throw new WorkflowConflictException('Shipment item reservation is inconsistent.');
                }
                $this->assertReceiptAllowed($locked, $item, $thread, $command->actor);
            }

            foreach ($entries as [, $item, $thread]) {
                $this->executeReceipt($locked, $thread, $command);
                $item->update([
                    'current_location' => $locked->direction->recipient()->value,
                    'active_shipment_id' => null,
                ]);
            }

            $from = $locked->status;
            $old = $this->snapshot($locked);
            $this->updateShipment($locked, ['status' => ShipmentStatus::DELIVERED->value, 'delivered_at' => now()]);
            $metadata = $this->entryMetadata($entries);
            $event = $this->recordEvent($locked, ShipmentAction::CONFIRM_SHIPMENT_DELIVERY, $command, $hash, $from, ShipmentStatus::DELIVERED, null, $metadata);
            $this->auditLogger->record('SHIPMENT_DELIVERED', $locked, $old, $this->snapshot($locked) + [
                'shipment_event_id' => $event->id,
                'items' => $metadata['items'],
            ], null, $command->actor);

            return $event;
        }, 3);
    }

    public function markTransportError(Shipment $shipment, ShipmentCommand $command): ShipmentEvent
    {
        $this->validateCommand($command);
        $validated = Validator::make($command->data, ['description' => ['required', 'string', 'max:5000']])->validate();
        $description = trim($validated['description']);
        if ($description === '') {
            throw ValidationException::withMessages(['description' => 'Transport error description is required.']);
        }
        $hash = $this->requestHash(ShipmentAction::MARK_TRANSPORT_ERROR, $command, ['shipment_id' => $shipment->id]);

        return DB::transaction(function () use ($shipment, $command, $description, $hash): ShipmentEvent {
            $locked = Shipment::query()->lockForUpdate()->findOrFail($shipment->id);
            if (($existing = $this->existingEvent($command, $hash, $locked->id)) !== null) {
                return $existing;
            }
            $this->assertExpectedVersion($locked, $command);
            $this->assertParticipant($command->actor, $locked);
            if ($locked->status !== ShipmentStatus::SENT) {
                throw new WorkflowConflictException('Transport error can only be marked on a SENT shipment.');
            }

            $from = $locked->status;
            $old = $this->snapshot($locked);
            $this->updateShipment($locked, [
                'status' => ShipmentStatus::TRANSPORT_ERROR->value,
                'transport_error_text' => $description,
            ]);
            $event = $this->recordEvent(
                $locked,
                ShipmentAction::MARK_TRANSPORT_ERROR,
                $command,
                $hash,
                $from,
                ShipmentStatus::TRANSPORT_ERROR,
                $description,
                ['reservation_released' => false],
            );
            $this->auditLogger->record('SHIPMENT_TRANSPORT_ERROR', $locked, $old, $this->snapshot($locked) + [
                'shipment_event_id' => $event->id,
            ], $description, $command->actor);

            return $event;
        }, 3);
    }

    private function preparedItemMutation(
        ShipmentAction $action,
        Shipment $shipment,
        Item $item,
        ProcessThread $thread,
        ShipmentCommand $command,
        \Closure $mutation,
    ): ShipmentEvent {
        $this->validateCommand($command);
        $context = ['shipment_id' => $shipment->id, 'item_id' => $item->id, 'process_thread_id' => $thread->id];
        $hash = $this->requestHash($action, $command, $context);

        return DB::transaction(function () use ($action, $shipment, $item, $thread, $command, $mutation, $context, $hash): ShipmentEvent {
            $lockedShipment = Shipment::query()->lockForUpdate()->findOrFail($shipment->id);
            if (($existing = $this->existingEvent($command, $hash, $lockedShipment->id)) !== null) {
                return $existing;
            }
            $this->assertExpectedVersion($lockedShipment, $command);
            $this->assertOperationalActor($command->actor, $lockedShipment->direction->sender());
            if ($lockedShipment->status !== ShipmentStatus::PREPARED) {
                throw new WorkflowConflictException('Shipment items can only change in PREPARED.');
            }

            $lockedItem = Item::query()->lockForUpdate()->findOrFail($item->id);
            $lockedThread = ProcessThread::query()->lockForUpdate()->findOrFail($thread->id);
            $old = $this->snapshot($lockedShipment);
            $mutation($lockedShipment, $lockedItem, $lockedThread);
            $this->updateShipment($lockedShipment, []);
            $event = $this->recordEvent(
                $lockedShipment,
                $action,
                $command,
                $hash,
                ShipmentStatus::PREPARED,
                ShipmentStatus::PREPARED,
                null,
                $context,
            );
            $this->auditLogger->record(
                $action === ShipmentAction::ADD_SHIPMENT_ITEM ? 'SHIPMENT_ITEM_ADDED' : 'SHIPMENT_ITEM_REMOVED',
                $lockedShipment,
                $old,
                $this->snapshot($lockedShipment) + $context + ['shipment_event_id' => $event->id],
                null,
                $command->actor,
            );

            return $event;
        }, 3);
    }

    private function assertEligible(Shipment $shipment, Item $item, ProcessThread $thread, User $actor): void
    {
        if ($item->order_id !== $thread->order_id || $item->current_location !== $shipment->direction->sender()->value) {
            throw new WorkflowConflictException('Item, thread, location and shipment direction are inconsistent.');
        }
        $order = $thread->order;

        if ($shipment->direction === ShipmentDirection::AK_TO_PL) {
            if ($thread->type !== ProcessThreadType::CUSTOM_REBUILD || $item->role !== ItemRole::CUSTOMER_ITEM) {
                throw new WorkflowConflictException('Item is not eligible for AK_TO_PL.');
            }
            $this->customGuard->assertActorMayPerform($actor, CustomRebuildAction::SEND_TO_POLAND);
            $this->customGuard->nextState($order, $thread, CustomRebuildAction::SEND_TO_POLAND);

            return;
        }

        if ($thread->type === ProcessThreadType::CUSTOM_REBUILD && $item->role === ItemRole::CUSTOMER_ITEM) {
            $this->customGuard->assertActorMayPerform($actor, CustomRebuildAction::SEND_TO_AUTOKLUBY);
            $this->customGuard->nextState($order, $thread, CustomRebuildAction::SEND_TO_AUTOKLUBY);

            return;
        }
        if (in_array($thread->type, [ProcessThreadType::STOCK_REQUEST, ProcessThreadType::STOCK_REPLENISHMENT], true)
            && $item->role === ($thread->type === ProcessThreadType::STOCK_REQUEST ? ItemRole::STOCK_ITEM : ItemRole::STOCK_REPLACEMENT)) {
            $this->stockGuard->assertActorMayPerform($actor, StockRebuildAction::SEND_TO_AUTOKLUBY);
            $this->stockGuard->nextState($order, $thread, StockRebuildAction::SEND_TO_AUTOKLUBY);

            return;
        }

        throw new WorkflowConflictException('Item is not eligible for PL_TO_AK.');
    }

    private function assertReceiptAllowed(Shipment $shipment, Item $item, ProcessThread $thread, User $actor): void
    {
        if ($item->order_id !== $thread->order_id) {
            throw new WorkflowConflictException('Item does not belong to the supplied process thread.');
        }
        $order = $thread->order;
        if ($shipment->direction === ShipmentDirection::AK_TO_PL
            && $thread->type === ProcessThreadType::CUSTOM_REBUILD
            && $item->role === ItemRole::CUSTOMER_ITEM) {
            $this->customGuard->assertActorMayPerform($actor, CustomRebuildAction::CONFIRM_PL_RECEIPT);
            $this->customGuard->nextState($order, $thread, CustomRebuildAction::CONFIRM_PL_RECEIPT);

            return;
        }
        if ($shipment->direction === ShipmentDirection::PL_TO_AK
            && $thread->type === ProcessThreadType::CUSTOM_REBUILD
            && $item->role === ItemRole::CUSTOMER_ITEM) {
            $this->customGuard->assertActorMayPerform($actor, CustomRebuildAction::CONFIRM_AK_RETURN_RECEIPT);
            $this->customGuard->nextState($order, $thread, CustomRebuildAction::CONFIRM_AK_RETURN_RECEIPT);

            return;
        }
        if ($shipment->direction === ShipmentDirection::PL_TO_AK
            && in_array($thread->type, [ProcessThreadType::STOCK_REQUEST, ProcessThreadType::STOCK_REPLENISHMENT], true)
            && $item->role === ($thread->type === ProcessThreadType::STOCK_REQUEST ? ItemRole::STOCK_ITEM : ItemRole::STOCK_REPLACEMENT)) {
            $this->stockGuard->assertActorMayPerform($actor, StockRebuildAction::CONFIRM_AK_STOCK_RECEIPT);
            $this->stockGuard->nextState($order, $thread, StockRebuildAction::CONFIRM_AK_STOCK_RECEIPT);

            return;
        }

        throw new WorkflowConflictException('No receipt action is available for this shipment item.');
    }

    private function executeDispatch(Shipment $shipment, ProcessThread $thread, ShipmentCommand $command): void
    {
        $key = $this->threadIdempotencyKey($shipment, $thread, ShipmentAction::SEND_SHIPMENT, $command);
        $data = ['shipment_public_number' => $shipment->public_number];
        if ($thread->type === ProcessThreadType::CUSTOM_REBUILD) {
            $action = $shipment->direction === ShipmentDirection::AK_TO_PL
                ? CustomRebuildAction::SEND_TO_POLAND
                : CustomRebuildAction::SEND_TO_AUTOKLUBY;
            $this->customWorkflow->execute($thread->order, new CustomRebuildCommand($action, $command->actor, $key, $data, $thread->lock_version));

            return;
        }

        $this->stockWorkflow->execute(
            $thread->order,
            $thread->type,
            new StockRebuildCommand(StockRebuildAction::SEND_TO_AUTOKLUBY, $command->actor, $key, $data, $thread->lock_version),
        );
    }

    private function executeReceipt(Shipment $shipment, ProcessThread $thread, ShipmentCommand $command): void
    {
        $key = $this->threadIdempotencyKey($shipment, $thread, ShipmentAction::CONFIRM_SHIPMENT_DELIVERY, $command);
        $data = ['shipment_public_number' => $shipment->public_number];
        if ($thread->type === ProcessThreadType::CUSTOM_REBUILD) {
            $action = $shipment->direction === ShipmentDirection::AK_TO_PL
                ? CustomRebuildAction::CONFIRM_PL_RECEIPT
                : CustomRebuildAction::CONFIRM_AK_RETURN_RECEIPT;
            $this->customWorkflow->execute($thread->order, new CustomRebuildCommand($action, $command->actor, $key, $data, $thread->lock_version));

            return;
        }

        $this->stockWorkflow->execute(
            $thread->order,
            $thread->type,
            new StockRebuildCommand(StockRebuildAction::CONFIRM_AK_STOCK_RECEIPT, $command->actor, $key, $data, $thread->lock_version),
        );
    }

    /** @return array<int, array{0: ShipmentItem, 1: Item, 2: ProcessThread}> */
    private function lockEntries(Shipment $shipment): array
    {
        $links = ShipmentItem::query()
            ->where('shipment_id', $shipment->id)
            ->orderBy('item_id')
            ->lockForUpdate()
            ->get();
        if ($links->isEmpty()) {
            return [];
        }
        $items = Item::query()->whereIn('id', $links->pluck('item_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $threads = ProcessThread::query()
            ->with(['order', 'events'])
            ->whereIn('id', $links->pluck('process_thread_id'))
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        return $links->map(function (ShipmentItem $link) use ($items, $threads): array {
            $item = $items->get($link->item_id);
            $thread = $threads->get($link->process_thread_id);
            if (! $item instanceof Item || ! $thread instanceof ProcessThread) {
                throw new WorkflowConflictException('Shipment item relation is incomplete.');
            }

            return [$link, $item, $thread];
        })->all();
    }

    private function validatedTrackingForSend(Shipment $shipment, mixed $value): ?string
    {
        $tracking = $this->normalizeTracking(is_string($value) ? $value : $shipment->tracking);
        if ($shipment->carrier->requiresTracking() && $tracking === null) {
            throw ValidationException::withMessages(['tracking' => 'Tracking is required for GLS and DPD.']);
        }
        if (! $shipment->carrier->requiresTracking() && $tracking !== null) {
            throw ValidationException::withMessages(['tracking' => 'SVOZ_AUTOKLUBY nesmí obsahovat tracking.']);
        }
        if ($tracking !== null && Shipment::query()
            ->where('carrier', $shipment->carrier)
            ->where('tracking', $tracking)
            ->whereKeyNot($shipment->id)
            ->exists()) {
            throw new WorkflowConflictException('Carrier tracking is already used by another shipment.');
        }

        return $tracking;
    }

    private function assertDirectionCarrier(ShipmentDirection $direction, ShipmentCarrier $carrier): void
    {
        if (! $direction->allowsCarrier($carrier)) {
            throw ValidationException::withMessages(['carrier' => 'Carrier is not allowed for this shipment direction.']);
        }
    }

    private function assertOperationalActor(User $actor, OrganizationCode $organization): void
    {
        if (! $actor->active || ! $actor->organization->active || $actor->organization->code !== $organization) {
            throw new AuthorizationException;
        }
        $roles = $organization === OrganizationCode::AUTOKLUBY
            ? [UserRole::AK_ADMIN, UserRole::AK_OPERATOR]
            : [UserRole::PL_MANAGER, UserRole::PL_OPERATOR];
        if (! in_array($actor->role, $roles, true)) {
            throw new AuthorizationException;
        }
    }

    private function assertParticipant(User $actor, Shipment $shipment): void
    {
        if (! in_array($actor->organization->code, [$shipment->direction->sender(), $shipment->direction->recipient()], true)) {
            throw new AuthorizationException;
        }
        $this->assertOperationalActor($actor, $actor->organization->code);
    }

    private function validateCommand(ShipmentCommand $command, bool $requireVersion = true): void
    {
        Validator::make([
            'idempotency_key' => $command->idempotencyKey,
            'expected_lock_version' => $command->expectedLockVersion,
        ], [
            'idempotency_key' => ['required', 'string', 'max:128'],
            'expected_lock_version' => $requireVersion ? ['required', 'integer', 'min:0'] : ['nullable', 'integer', 'min:0'],
        ])->validate();
        if (trim($command->idempotencyKey) === '') {
            throw ValidationException::withMessages(['idempotency_key' => 'Idempotency key is required.']);
        }
    }

    private function assertExpectedVersion(Shipment $shipment, ShipmentCommand $command): void
    {
        if ($command->expectedLockVersion !== $shipment->lock_version) {
            throw new WorkflowConflictException('Shipment version conflict.');
        }
    }

    private function updateShipment(Shipment $shipment, array $values): void
    {
        $oldVersion = $shipment->lock_version;
        $updated = Shipment::query()->whereKey($shipment->id)->where('lock_version', $oldVersion)->update($values + [
            'lock_version' => $oldVersion + 1,
            'updated_at' => now(),
        ]);
        if ($updated !== 1) {
            throw new WorkflowConflictException('Shipment version conflict.');
        }
        $shipment->refresh();
    }

    private function recordEvent(
        Shipment $shipment,
        ShipmentAction $action,
        ShipmentCommand $command,
        string $hash,
        ?ShipmentStatus $from,
        ShipmentStatus $to,
        ?string $reason,
        array $metadata,
    ): ShipmentEvent {
        return $shipment->events()->create([
            'action' => $action->value,
            'idempotency_key' => $command->idempotencyKey,
            'request_hash' => $hash,
            'from_status' => $from?->value,
            'to_status' => $to->value,
            'actor_user_id' => $command->actor->id,
            'actor_organization_id' => $command->actor->organization_id,
            'reason' => $reason,
            'metadata' => $metadata,
        ]);
    }

    private function existingEvent(ShipmentCommand $command, string $hash, ?int $shipmentId = null): ?ShipmentEvent
    {
        $event = ShipmentEvent::query()->where('idempotency_key', $command->idempotencyKey)->first();
        if ($event === null) {
            return null;
        }
        if ($event->request_hash !== $hash || ($shipmentId !== null && $event->shipment_id !== $shipmentId)) {
            throw new WorkflowConflictException('Idempotency key was reused with a different request.');
        }

        return $event;
    }

    private function requestHash(ShipmentAction $action, ShipmentCommand $command, array $context = []): string
    {
        return hash('sha256', json_encode($this->normalizeArray([
            'action' => $action->value,
            'actor_id' => $command->actor->id,
            'data' => $command->data,
            'expected_lock_version' => $command->expectedLockVersion,
            'context' => $context,
        ]), JSON_THROW_ON_ERROR));
    }

    private function normalizeArray(array $data): array
    {
        ksort($data);
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->normalizeArray($value);
            }
        }

        return $data;
    }

    private function threadIdempotencyKey(Shipment $shipment, ProcessThread $thread, ShipmentAction $action, ShipmentCommand $command): string
    {
        return hash('sha256', implode('|', [$shipment->id, $thread->id, $action->value, $command->idempotencyKey]));
    }

    private function normalizeTracking(?string $tracking): ?string
    {
        $tracking = trim((string) $tracking);

        return $tracking === '' ? null : $tracking;
    }

    private function snapshot(Shipment $shipment): array
    {
        return [
            'public_number' => $shipment->public_number,
            'direction' => $shipment->direction->value,
            'carrier' => $shipment->carrier->value,
            'tracking' => $shipment->tracking,
            'status' => $shipment->status->value,
            'sender_organization_id' => $shipment->sender_organization_id,
            'recipient_organization_id' => $shipment->recipient_organization_id,
            'sent_at' => $shipment->sent_at?->toAtomString(),
            'delivered_at' => $shipment->delivered_at?->toAtomString(),
            'transport_error_text' => $shipment->transport_error_text,
            'lock_version' => $shipment->lock_version,
        ];
    }

    private function entryMetadata(array $entries): array
    {
        return ['items' => array_map(static fn (array $entry): array => [
            'item_id' => $entry[1]->id,
            'process_thread_id' => $entry[2]->id,
            'order_id' => $entry[2]->order_id,
        ], $entries)];
    }
}
