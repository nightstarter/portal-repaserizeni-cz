<?php

namespace App\Application\Orders;

use App\Enums\OrganizationCode;
use App\Models\AuditLog;
use App\Models\ProcessThread;
use App\Models\User;

final class CustomRebuildTimeline
{
    private const SHARED_METADATA = [
        'inspection_result', 'fault_code', 'axial_rods', 'routing', 'axial_rods_surcharge_czk',
    ];

    private const AK_ONLY_METADATA = ['condition_description', 'fault_note'];

    /**
     * @return array<int, array<string, mixed>>
     */
    public function for(ProcessThread $thread, User $viewer): array
    {
        $thread->loadMissing(['events.actorUser', 'events.actorOrganization']);
        $isAutokluby = $viewer->organization->code === OrganizationCode::AUTOKLUBY;
        $audits = $isAutokluby
            ? AuditLog::query()->where('order_id', $thread->order_id)->get()->keyBy(
                fn (AuditLog $audit): mixed => $audit->new_values['thread_event_id'] ?? 'audit-'.$audit->id,
            )
            : collect();
        $metadataKeys = $isAutokluby
            ? [...self::SHARED_METADATA, ...self::AK_ONLY_METADATA]
            : self::SHARED_METADATA;

        return $thread->events
            ->sortByDesc('id')
            ->map(function ($event) use ($audits, $isAutokluby, $metadataKeys): array {
                $metadata = collect((array) $event->metadata)
                    ->only($metadataKeys)
                    ->filter(static fn (mixed $value): bool => is_scalar($value) || $value === null)
                    ->all();

                return [
                    'performed_at' => $event->performed_at,
                    'action' => $event->action,
                    'from_state' => $event->from_state,
                    'to_state' => $event->to_state,
                    'actor_organization' => $event->actorOrganization?->code?->value ?? $event->actor_type,
                    'actor_name' => $event->actorUser?->name,
                    'metadata' => $metadata,
                    'reason' => $isAutokluby ? $event->reason : null,
                    'audited' => $isAutokluby && $audits->has($event->id),
                ];
            })
            ->values()
            ->all();
    }
}
