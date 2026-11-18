<?php

namespace App\Application\Messages;

use App\Application\Audit\AuditLogger;
use App\Domain\Messages\MessagePosted;
use App\Mail\DiscussionMessageNotificationMail;
use App\Models\OutboxEvent;
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
        if (! config('discussion_notifications.enabled')) {
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
                $event->loadMissing(['recipient.organization', 'message.order']);
                $recipient = $event->recipient;
                $message = $event->message;
                if ($recipient === null || $message === null || ! $recipient->active || ! $recipient->organization?->active
                    || ! Gate::forUser($recipient)->allows('view', $message->order)) {
                    $this->failPermanently($event, 'Recipient is no longer eligible.');
                    $result['failed']++;

                    continue;
                }

                $locale = in_array($recipient->preferred_locale, ['cs', 'pl'], true) ? $recipient->preferred_locale : 'cs';
                Mail::to($recipient->email)
                    ->locale($locale)
                    ->send(new DiscussionMessageNotificationMail(
                        $message->order->order_no,
                        route('orders.discussion.index', $message->order),
                        $locale,
                    ));
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
                ->where('event_type', MessagePosted::TYPE)
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
            $updated = OutboxEvent::query()
                ->whereKey($event->id)
                ->where('status', OutboxEvent::PROCESSING)
                ->where('lock_token', $event->lock_token)
                ->update([
                    'status' => OutboxEvent::SENT,
                    'processed_at' => now(),
                    'sent_at' => now(),
                    'failed_at' => null,
                    'next_attempt_at' => null,
                    'locked_at' => null,
                    'lock_token' => null,
                    'last_error' => null,
                    'updated_at' => now(),
                ]);
            if ($updated === 1) {
                $this->auditLogger->record('DISCUSSION_NOTIFICATION_SENT', $event->message, [], [
                    'outbox_event_id' => $event->id,
                    'recipient_user_id' => $event->recipient_user_id,
                    'attempts' => $event->attempts,
                ]);
            }
        }, 3);
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
                $this->auditLogger->record('DISCUSSION_NOTIFICATION_FAILED', $event->message, [], [
                    'outbox_event_id' => $event->id,
                    'recipient_user_id' => $event->recipient_user_id,
                    'attempts' => $event->attempts,
                    'reason' => mb_substr($safeReason, 0, 500),
                ]);
            }
        }, 3);
    }
}
