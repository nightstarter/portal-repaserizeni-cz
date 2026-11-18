<?php

namespace App\Application\Messages;

use App\Domain\Messages\MessagePosted;
use App\Enums\OrganizationCode;
use App\Models\Message;
use App\Models\Organization;
use App\Models\OutboxEvent;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

final class DiscussionNotificationOutbox
{
    public function enqueue(Message $message): int
    {
        $message->loadMissing(['order', 'authorOrganization']);
        $targetCode = $message->authorOrganization->code === OrganizationCode::AUTOKLUBY
            ? OrganizationCode::POLAND
            : OrganizationCode::AUTOKLUBY;
        $targetOrganization = Organization::query()
            ->where('code', $targetCode)
            ->where('active', true)
            ->first();

        if ($targetOrganization === null) {
            return $this->enqueueWithoutRecipient($message, new MessagePosted($message));
        }

        $event = new MessagePosted($message);
        $created = 0;
        $eligible = 0;
        User::query()
            ->with('organization')
            ->where('organization_id', $targetOrganization->id)
            ->where('active', true)
            ->whereKeyNot($message->author_user_id)
            ->orderBy('id')
            ->each(function (User $recipient) use ($message, $event, &$created, &$eligible): void {
                if (! Gate::forUser($recipient)->allows('view', $message->order)) {
                    return;
                }

                $eligible++;
                $outbox = OutboxEvent::query()->firstOrCreate(
                    ['deduplication_key' => 'discussion-email:'.$message->id.':'.$recipient->id],
                    [
                        'event_uuid' => (string) Str::uuid(),
                        'event_type' => MessagePosted::TYPE,
                        'aggregate_type' => Message::class,
                        'aggregate_id' => $message->id,
                        'recipient_user_id' => $recipient->id,
                        'payload' => $event->payload() + ['recipient_user_id' => $recipient->id],
                        'status' => OutboxEvent::PENDING,
                        'attempts' => 0,
                        'available_at' => now(),
                        'next_attempt_at' => now(),
                    ],
                );
                $created += $outbox->wasRecentlyCreated ? 1 : 0;
            });

        if ($eligible === 0) {
            $created += $this->enqueueWithoutRecipient($message, $event);
        }

        return $created;
    }

    private function enqueueWithoutRecipient(Message $message, MessagePosted $event): int
    {
        $outbox = OutboxEvent::query()->firstOrCreate(
            ['deduplication_key' => 'discussion-email:'.$message->id.':no-recipient'],
            [
                'event_uuid' => (string) Str::uuid(),
                'event_type' => MessagePosted::TYPE,
                'aggregate_type' => Message::class,
                'aggregate_id' => $message->id,
                'recipient_user_id' => null,
                'payload' => $event->payload() + ['recipient_user_id' => null],
                'status' => OutboxEvent::PENDING,
                'attempts' => 0,
                'available_at' => now(),
                'next_attempt_at' => now(),
            ],
        );

        return $outbox->wasRecentlyCreated ? 1 : 0;
    }
}
