<?php

namespace App\Application\Messages;

use App\Application\Audit\AuditLogger;
use App\Domain\Messages\MessagePosted;
use App\Models\OutboxEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class DiscussionNotificationAdminService
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function retry(OutboxEvent $event, User $actor): bool
    {
        return DB::transaction(function () use ($event, $actor): bool {
            $locked = OutboxEvent::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();
            if ($locked->event_type !== MessagePosted::TYPE || $locked->recipient_user_id === null
                || $locked->status !== OutboxEvent::FAILED) {
                return false;
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
            $this->auditLogger->record('DISCUSSION_NOTIFICATION_MANUAL_RETRY', $locked->message, [], [
                'outbox_event_id' => $locked->id,
                'recipient_user_id' => $locked->recipient_user_id,
            ], actor: $actor);

            return true;
        }, 3);
    }
}
