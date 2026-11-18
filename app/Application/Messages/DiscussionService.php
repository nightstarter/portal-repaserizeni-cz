<?php

namespace App\Application\Messages;

use App\Application\Audit\AuditLogger;
use App\Exceptions\WorkflowConflictException;
use App\Models\Message;
use App\Models\Order;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class DiscussionService
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly DiscussionNotificationOutbox $notificationOutbox,
    ) {}

    public function post(Order $order, PostMessageCommand $command): Message
    {
        if (! $command->actor->can('create', [Message::class, $order])) {
            throw new AuthorizationException;
        }
        $body = trim($command->body);
        Validator::make([
            'body' => $body,
            'idempotency_key' => $command->idempotencyKey,
            'attachment_count' => count($command->attachments),
        ], [
            'body' => ['required', 'string', 'max:10000'],
            'idempotency_key' => ['required', 'string', 'max:120'],
            'attachment_count' => ['integer', 'max:'.config('messages.max_attachments', 5)],
        ], [
            'body.required' => __('app.message_required'),
            'body.max' => __('app.message_too_long'),
            'attachment_count.max' => __('app.message_too_many_attachments'),
        ], [
            'body' => __('app.message_text'),
            'attachment_count' => __('app.message_attachments'),
        ])->validate();

        $attachments = collect($command->attachments)
            ->map(fn (mixed $file): array => $this->validateAttachment($file))
            ->all();
        $requestHash = $this->requestHash($order, $command, $body, $attachments);
        if (($existing = $this->existing($command->idempotencyKey, $requestHash)) !== null) {
            return $existing;
        }

        $stored = $this->storeAttachments($attachments);
        try {
            return DB::transaction(function () use ($order, $command, $body, $requestHash, $stored): Message {
                if (($existing = $this->existing($command->idempotencyKey, $requestHash)) !== null) {
                    $this->deleteStored($stored);

                    return $existing;
                }

                $message = Message::query()->create([
                    'public_token' => (string) Str::uuid(),
                    'order_id' => $order->id,
                    'author_user_id' => $command->actor->id,
                    'author_organization_id' => $command->actor->organization_id,
                    'body' => $body,
                    'idempotency_key' => $command->idempotencyKey,
                    'request_hash' => $requestHash,
                ]);
                foreach ($stored as $attachment) {
                    $message->attachments()->create([
                        'public_token' => (string) Str::uuid(),
                        'storage_disk' => $attachment['storage_disk'],
                        'storage_path' => $attachment['storage_path'],
                        'original_name' => $attachment['original_name'],
                        'mime' => $attachment['mime'],
                        'extension' => $attachment['extension'],
                        'size' => $attachment['size'],
                        'checksum_sha256' => $attachment['checksum_sha256'],
                    ]);
                }
                $message->reads()->create(['user_id' => $command->actor->id, 'read_at' => now()]);

                $this->notificationOutbox->enqueue($message);
                $this->auditLogger->record('DISCUSSION_MESSAGE_POSTED', $message, [], [
                    'attachment_count' => count($stored),
                    'attachment_checksums' => array_column($stored, 'checksum_sha256'),
                ], actor: $command->actor);

                return $message->load('attachments');
            }, 3);
        } catch (\Throwable $exception) {
            $this->deleteStored($stored);
            throw $exception;
        }
    }

    public function unreadCount(Order $order, User $user): int
    {
        $this->authorize($order, $user);

        return $order->messages()->unreadFor($user)->count();
    }

    public function globalUnreadCount(User $user): int
    {
        return Message::query()
            ->unreadFor($user)
            ->whereHas('order', fn ($orders) => $orders->visibleTo($user))
            ->count();
    }

    public function markRead(Order $order, User $user): int
    {
        $this->authorize($order, $user);
        $ids = $order->messages()->unreadFor($user)->pluck('id');
        $readAt = now();
        $inserted = 0;
        foreach ($ids as $messageId) {
            $inserted += DB::table('message_reads')->insertOrIgnore([
                'message_id' => $messageId,
                'user_id' => $user->id,
                'read_at' => $readAt,
            ]);
        }

        return $inserted;
    }

    private function authorize(Order $order, User $user): void
    {
        if (! $user->can('view', $order)) {
            throw new AuthorizationException;
        }
    }

    private function validateAttachment(mixed $file): array
    {
        if (! $file instanceof UploadedFile || ! $file->isValid()) {
            throw ValidationException::withMessages(['attachments' => __('app.message_attachment_invalid')]);
        }
        $original = $file->getClientOriginalName();
        if (basename(str_replace('\\', '/', $original)) !== $original || str_contains($original, '..') || substr_count($original, '.') !== 1) {
            throw ValidationException::withMessages(['attachments' => __('app.message_attachment_unsafe_name')]);
        }
        $extension = strtolower($file->getClientOriginalExtension());
        $allowed = config('messages.allowed', []);
        if (! isset($allowed[$extension])) {
            throw ValidationException::withMessages(['attachments' => __('app.message_attachment_type')]);
        }
        $size = $file->getSize();
        if (! is_int($size) || $size < 1 || $size > ((int) config('messages.max_kilobytes', 10240) * 1024)) {
            throw ValidationException::withMessages(['attachments' => __('app.message_attachment_size')]);
        }
        $actualMime = $file->getMimeType();
        $declaredMime = $file->getClientMimeType();
        if (! in_array($actualMime, $allowed[$extension], true) || ! in_array($declaredMime, $allowed[$extension], true)) {
            throw ValidationException::withMessages(['attachments' => __('app.message_attachment_mime')]);
        }
        $safeName = preg_replace('/[^\pL\pN._ -]+/u', '_', $original) ?: 'attachment.'.$extension;

        return [
            'file' => $file,
            'original_name' => mb_substr($safeName, 0, 255),
            'extension' => $extension,
            'mime' => $actualMime,
            'size' => $size,
            'checksum_sha256' => hash_file('sha256', $file->getRealPath()),
        ];
    }

    private function storeAttachments(array $attachments): array
    {
        $stored = [];
        foreach ($attachments as $attachment) {
            $disk = (string) config('messages.disk', 'local');
            $path = 'message-attachments/'.now()->format('Y/m').'/'.Str::uuid().'.'.$attachment['extension'];
            $stream = fopen($attachment['file']->getRealPath(), 'rb');
            try {
                $success = $stream !== false && Storage::disk($disk)->put($path, $stream);
            } catch (\Throwable) {
                $success = false;
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
            if (! $success) {
                $this->deleteStored($stored);
                throw ValidationException::withMessages(['attachments' => __('app.message_attachment_store_failed')]);
            }
            unset($attachment['file']);
            $stored[] = $attachment + ['storage_disk' => $disk, 'storage_path' => $path];
        }

        return $stored;
    }

    private function deleteStored(array $attachments): void
    {
        foreach ($attachments as $attachment) {
            if (isset($attachment['storage_disk'], $attachment['storage_path'])) {
                Storage::disk($attachment['storage_disk'])->delete($attachment['storage_path']);
            }
        }
    }

    private function existing(string $key, string $hash): ?Message
    {
        $message = Message::query()->where('idempotency_key', $key)->first();
        if ($message === null) {
            return null;
        }
        if ($message->request_hash !== $hash) {
            throw new WorkflowConflictException('Message idempotency key was reused with a different payload.');
        }

        return $message;
    }

    private function requestHash(Order $order, PostMessageCommand $command, string $body, array $attachments): string
    {
        $files = array_map(static fn (array $attachment): array => [
            'name' => $attachment['original_name'],
            'mime' => $attachment['mime'],
            'size' => $attachment['size'],
            'checksum' => $attachment['checksum_sha256'],
        ], $attachments);

        return hash('sha256', json_encode([
            'order_id' => $order->id,
            'actor_id' => $command->actor->id,
            'body' => $body,
            'attachments' => $files,
        ], JSON_THROW_ON_ERROR));
    }
}
