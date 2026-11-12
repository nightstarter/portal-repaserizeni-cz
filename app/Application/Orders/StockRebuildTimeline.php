<?php

namespace App\Application\Orders;

use App\Enums\OrganizationCode;
use App\Models\AuditLog;
use App\Models\ProcessThread;
use App\Models\User;
use Illuminate\Support\Collection;

final class StockRebuildTimeline
{
    private const SHARED_METADATA = ['eta'];

    private const AK_ONLY_METADATA = ['decision', 'observed_at', 'source_reference', 'scenario'];

    /**
     * @param  Collection<int, ProcessThread>  $threads
     * @return array<int, array<string, mixed>>
     */
    public function for(Collection $threads, User $viewer): array
    {
        $threads->loadMissing(['events.actorUser', 'events.actorOrganization']);
        $isAutokluby = $viewer->organization->code === OrganizationCode::AUTOKLUBY;
        $orderIds = $threads->pluck('order_id')->unique();
        $audits = $isAutokluby
            ? AuditLog::query()->whereIn('order_id', $orderIds)->get()->keyBy(
                fn (AuditLog $audit): mixed => $audit->new_values['thread_event_id'] ?? 'audit-'.$audit->id,
            )
            : collect();
        $metadataKeys = $isAutokluby
            ? [...self::SHARED_METADATA, ...self::AK_ONLY_METADATA]
            : self::SHARED_METADATA;

        return $threads
            ->flatMap(fn (ProcessThread $thread) => $thread->events->map(function ($event) use ($thread, $audits, $isAutokluby, $metadataKeys): array {
                $metadata = collect((array) $event->metadata)
                    ->only($metadataKeys)
                    ->filter(static fn (mixed $value): bool => is_scalar($value) || $value === null)
                    ->all();

                return [
                    'performed_at' => $event->performed_at,
                    'action' => $event->action,
                    'thread_type' => $thread->type,
                    'from_state' => $event->from_state,
                    'to_state' => $event->to_state,
                    'actor_organization' => $event->actorOrganization?->code?->value ?? $event->actor_type,
                    'actor_name' => $event->actorUser?->name,
                    'metadata' => $metadata,
                    'reason' => $isAutokluby ? $event->reason : null,
                    'audited' => $isAutokluby && $audits->has($event->id),
                ];
            }))
            ->sortByDesc('performed_at')
            ->values()
            ->all();
    }
}
