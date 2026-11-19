<?php

namespace App\Application\Messages;

use App\Application\Audit\AuditLogger;
use App\Application\Finance\MinorMoney;
use App\Domain\Buybacks\BuybackCustomerOfferQueued;
use App\Domain\Messages\MessagePosted;
use App\Enums\BuybackAction;
use App\Enums\BuybackPriceType;
use App\Enums\BuybackState;
use App\Enums\Permission;
use App\Enums\ResponsibleParty;
use App\Mail\BuybackCustomerOfferMail;
use App\Mail\DiscussionMessageNotificationMail;
use App\Models\OutboxEvent;
use App\Models\ProcessThread;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

final class DiscussionNotificationDispatcher
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function dispatch(int $limit): array
    {
        $result = ['claimed' => 0, 'sent' => 0, 'retried' => 0, 'failed' => 0];
        if (! config('discussion_notifications.enabled') && ! config('buyback.customer_email_enabled')) {
            return $result + ['disabled' => true];
        }

        $limit = max(1, min($limit, 500));
        for ($processed = 0; $processed < $limit; $processed++) {
            $event = $this->claimNext();
            if ($event === null) {
                break;
            }

            $result['claimed']++;
            try {
                $eligible = $event->event_type === MessagePosted::TYPE
                    ? $this->sendDiscussion($event)
                    : $this->sendBuyback($event);
                if (! $eligible) {
                    $this->failPermanently($event, 'Notification is no longer eligible for delivery.');
                    $result['failed']++;

                    continue;
                }
                $this->markSent($event);
                $result['sent']++;
            } catch (\Throwable) {
                if ($event->attempts >= max(1, (int) config('discussion_notifications.max_attempts', 5))) {
                    $this->failPermanently($event, 'E-mail delivery failed after the maximum number of attempts.');
                    $result['failed']++;
                } else {
                    $this->scheduleRetry($event);
                    $result['retried']++;
                }
            }
        }

        return $result + ['disabled' => false];
    }

    private function claimNext(): ?OutboxEvent
    {
        return DB::transaction(function (): ?OutboxEvent {
            $now = now();
            $staleBefore = $now->copy()->subMinutes(max(1, (int) config('discussion_notifications.lock_minutes', 10)));
            $event = OutboxEvent::query()
                ->whereIn('event_type', $this->enabledTypes())
                ->where(function ($query) use ($now, $staleBefore): void {
                    $query->where(function ($pending) use ($now): void {
                        $pending->where('status', OutboxEvent::PENDING)
                            ->where(function ($due) use ($now): void {
                                $due->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', $now);
                            });
                    })->orWhere(function ($processing) use ($staleBefore): void {
                        $processing->where('status', OutboxEvent::PROCESSING)
                            ->where(function ($abandoned) use ($staleBefore): void {
                                $abandoned->whereNull('locked_at')->orWhere('locked_at', '<=', $staleBefore);
                            });
                    });
                })
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if ($event === null) {
                return null;
            }

            $event->forceFill([
                'status' => OutboxEvent::PROCESSING,
                'attempts' => $event->attempts + 1,
                'locked_at' => $now,
                'lock_token' => (string) Str::uuid(),
                'last_error' => null,
            ])->save();

            return $event->fresh();
        }, 3);
    }

    private function markSent(OutboxEvent $event): void
    {
        DB::transaction(function () use ($event): void {
            $lockedEvent = OutboxEvent::query()
                ->whereKey($event->id)
                ->where('status', OutboxEvent::PROCESSING)
                ->where('lock_token', $event->lock_token)
                ->lockForUpdate()
                ->first();
            if ($lockedEvent === null) {
                return;
            }

            $subject = $event->event_type === MessagePosted::TYPE
                ? $event->message
                : $this->markBuybackOfferDelivered($lockedEvent);
            $lockedEvent->forceFill([
                'status' => OutboxEvent::SENT,
                'processed_at' => now(),
                'sent_at' => now(),
                'failed_at' => null,
                'next_attempt_at' => null,
                'locked_at' => null,
                'lock_token' => null,
                'last_error' => null,
            ])->save();
            $this->auditLogger->record($event->event_type === MessagePosted::TYPE ? 'DISCUSSION_NOTIFICATION_SENT' : 'BUYBACK_CUSTOMER_NOTIFICATION_SENT', $subject, [], [
                'outbox_event_id' => $event->id,
                'recipient_user_id' => $event->recipient_user_id,
                'attempts' => $event->attempts,
            ]);
        }, 3);
    }

    private function markBuybackOfferDelivered(OutboxEvent $event): ProcessThread
    {
        $thread = ProcessThread::query()->whereKey($event->aggregate_id)->lockForUpdate()->firstOrFail();
        $idempotencyKey = 'buyback-email-delivered:'.$event->id;
        if ($thread->events()->where('idempotency_key', $idempotencyKey)->exists()) {
            return $thread;
        }
        if (BuybackState::from($thread->state) !== BuybackState::CUSTOMER_OFFER_READY) {
            throw new \LogicException('Buyback customer offer is not ready for delivery completion.');
        }

        $thread->forceFill([
            'state' => BuybackState::OFFER_SENT->value,
            'responsible_party' => ResponsibleParty::CUSTOMER->value,
            'responsible_organization_id' => null,
            'lock_version' => $thread->lock_version + 1,
        ])->save();
        $thread->events()->create([
            'order_id' => $thread->order_id,
            'action' => BuybackAction::DELIVER_CUSTOMER_OFFER->value,
            'idempotency_key' => $idempotencyKey,
            'request_hash' => hash('sha256', $event->event_uuid),
            'from_state' => BuybackState::CUSTOMER_OFFER_READY->value,
            'to_state' => BuybackState::OFFER_SENT->value,
            'actor_type' => 'SYSTEM',
            'metadata' => ['outbox_event_id' => $event->id],
        ]);

        return $thread->fresh();
    }

    private function scheduleRetry(OutboxEvent $event): void
    {
        $base = max(1, (int) config('discussion_notifications.retry_minutes', 5));
        $delay = $base * (2 ** max(0, $event->attempts - 1));
        OutboxEvent::query()
            ->whereKey($event->id)
            ->where('status', OutboxEvent::PROCESSING)
            ->where('lock_token', $event->lock_token)
            ->update([
                'status' => OutboxEvent::PENDING,
                'next_attempt_at' => now()->addMinutes($delay),
                'available_at' => now()->addMinutes($delay),
                'locked_at' => null,
                'lock_token' => null,
                'last_error' => 'Temporary e-mail delivery failure; retry scheduled.',
                'updated_at' => now(),
            ]);
    }

    private function failPermanently(OutboxEvent $event, string $safeReason): void
    {
        DB::transaction(function () use ($event, $safeReason): void {
            $updated = OutboxEvent::query()
                ->whereKey($event->id)
                ->where('status', OutboxEvent::PROCESSING)
                ->where('lock_token', $event->lock_token)
                ->update([
                    'status' => OutboxEvent::FAILED,
                    'failed_at' => now(),
                    'next_attempt_at' => null,
                    'locked_at' => null,
                    'lock_token' => null,
                    'last_error' => mb_substr($safeReason, 0, 500),
                    'updated_at' => now(),
                ]);
            if ($updated === 1) {
                $this->auditLogger->record($event->event_type === MessagePosted::TYPE ? 'DISCUSSION_NOTIFICATION_FAILED' : 'BUYBACK_CUSTOMER_NOTIFICATION_FAILED', $this->subject($event), [], [
                    'outbox_event_id' => $event->id,
                    'recipient_user_id' => $event->recipient_user_id,
                    'attempts' => $event->attempts,
                    'reason' => mb_substr($safeReason, 0, 500),
                ]);
            }
        }, 3);
    }

    private function enabledTypes(): array
    {
        $types = [];
        if (config('discussion_notifications.enabled')) {
            $types[] = MessagePosted::TYPE;
        }
        if (config('buyback.customer_email_enabled')) {
            $types[] = BuybackCustomerOfferQueued::TYPE;
        }

        return $types;
    }

    private function sendDiscussion(OutboxEvent $event): bool
    {
        $event->loadMissing(['recipient.organization', 'message.order']);
        $recipient = $event->recipient;
        $message = $event->message;
        if ($recipient === null || $message === null || ! $recipient->active || ! $recipient->organization?->active
            || ! Gate::forUser($recipient)->allows('view', $message->order)) {
            return false;
        }
        $locale = in_array($recipient->preferred_locale, ['cs', 'pl'], true) ? $recipient->preferred_locale : 'cs';
        Mail::to($recipient->email)->locale($locale)->send(new DiscussionMessageNotificationMail(
            $message->order->order_no, route('orders.discussion.index', $message->order), $locale,
        ));

        return true;
    }

    private function sendBuyback(OutboxEvent $event): bool
    {
        $thread = ProcessThread::query()->with(['order.customer', 'buybackPriceQuotes', 'events.actorUser.organization'])
            ->find($event->aggregate_id);
        $approval = $thread?->events->where('action', BuybackAction::APPROVE_FINAL_PRICE->value)->sortByDesc('id')->first();
        $final = $thread?->buybackPriceQuotes->where('type', BuybackPriceType::FINAL_CUSTOMER)->sortByDesc('version')->first();
        $email = $thread?->order?->customer?->email;
        if ($thread === null || BuybackState::from($thread->state) !== BuybackState::CUSTOMER_OFFER_READY
            || $approval?->actorUser === null
            || ! $approval->actorUser->hasPermission(Permission::BUYBACK_FINAL_PRICE_APPROVE)
            || $final === null || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        Mail::to($email)->locale('cs')->send(new BuybackCustomerOfferMail(
            $thread->order->order_no, MinorMoney::format($final->amount_minor), $final->currency,
        ));

        return true;
    }

    private function subject(OutboxEvent $event): mixed
    {
        return $event->event_type === MessagePosted::TYPE ? $event->message : $event->buybackThread;
    }
}
