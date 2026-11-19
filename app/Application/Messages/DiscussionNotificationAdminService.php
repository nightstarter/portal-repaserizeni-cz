<?php

namespace App\Application\Messages;

use App\Application\Audit\AuditLogger;
use App\Domain\Buybacks\BuybackCustomerOfferQueued;
use App\Domain\Messages\MessagePosted;
use App\Enums\BuybackAction;
use App\Enums\BuybackState;
use App\Enums\Permission;
use App\Models\OutboxEvent;
use App\Models\ProcessThread;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class DiscussionNotificationAdminService
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function retry(OutboxEvent $event, User $actor): bool
    {
        return DB::transaction(function () use ($event, $actor): bool {
            $locked = OutboxEvent::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();
            if (! in_array($locked->event_type, [MessagePosted::TYPE, BuybackCustomerOfferQueued::TYPE], true)
                || $locked->status !== OutboxEvent::FAILED) {
                return false;
            }
            if ($locked->event_type === MessagePosted::TYPE && $locked->recipient_user_id === null) {
                return false;
            }
            if ($locked->event_type === BuybackCustomerOfferQueued::TYPE) {
                if (! config('buyback.customer_email_enabled')) {
                    return false;
                }
                $thread = ProcessThread::query()->with('events.actorUser.organization')->find($locked->aggregate_id);
                $approval = $thread?->events->where('action', BuybackAction::APPROVE_FINAL_PRICE->value)->sortByDesc('id')->first();
                if ($thread === null || BuybackState::from($thread->state) !== BuybackState::OFFER_SENT
                    || $approval?->actorUser === null || ! $approval->actorUser->hasPermission(Permission::BUYBACK_FINAL_PRICE_APPROVE)) {
                    return false;
                }
            }

            $locked->forceFill([
                'status' => OutboxEvent::PENDING,
                'attempts' => 0,
                'available_at' => now(),
                'next_attempt_at' => now(),
                'processed_at' => null,
                'failed_at' => null,
                'locked_at' => null,
                'lock_token' => null,
                'last_error' => null,
            ])->save();
            $this->auditLogger->record($locked->event_type === MessagePosted::TYPE ? 'DISCUSSION_NOTIFICATION_MANUAL_RETRY' : 'BUYBACK_CUSTOMER_NOTIFICATION_MANUAL_RETRY',
                $locked->event_type === MessagePosted::TYPE ? $locked->message : $locked->buybackThread, [], [
                    'outbox_event_id' => $locked->id,
                    'recipient_user_id' => $locked->recipient_user_id,
                ], actor: $actor);

            return true;
        }, 3);
    }
}
