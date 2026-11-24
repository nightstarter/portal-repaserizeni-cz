<?php

namespace App\Operations;

use App\Models\MonitorRun;
use App\Models\OperationalAlert;
use App\Models\OperationalAlertEvent;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class OperationalAlertManager
{
    /** @param list<MonitorCheck> $checks
     * @return list<array{alert:OperationalAlert,event:string}>
     */
    public function apply(array $checks, MonitorRun $run): array
    {
        $changes = [];
        foreach ($checks as $check) {
            array_push($changes, ...$this->applyCheck($check, $run));
        }

        return $changes;
    }

    public function acknowledge(OperationalAlert $alert, User $actor, string $note, int $expectedVersion): OperationalAlert
    {
        $note = trim($note);
        if ($note === '' || mb_strlen($note) > 500) {
            throw new RuntimeException('ACK_NOTE_INVALID');
        }

        return DB::transaction(function () use ($alert, $actor, $note, $expectedVersion): OperationalAlert {
            $updated = OperationalAlert::query()
                ->whereKey($alert->id)
                ->where('row_version', $expectedVersion)
                ->whereIn('lifecycle', [OperationalAlert::OPEN, OperationalAlert::ACKNOWLEDGED])
                ->update([
                    'lifecycle' => OperationalAlert::ACKNOWLEDGED,
                    'acknowledged_by' => $actor->id,
                    'acknowledged_at' => now(),
                    'acknowledgement_note' => $note,
                    'row_version' => DB::raw('row_version + 1'),
                    'updated_at' => now(),
                ]);
            if ($updated !== 1) {
                throw new RuntimeException('ALERT_VERSION_CONFLICT');
            }
            $fresh = OperationalAlert::query()->findOrFail($alert->id);
            $this->event($fresh, null, 'ACKNOWLEDGED', $actor->id, $note);

            return $fresh;
        }, 3);
    }

    /** @return list<array{alert:OperationalAlert,event:string}> */
    private function applyCheck(MonitorCheck $check, MonitorRun $run): array
    {
        if (in_array($check->severity, [MonitorCheck::OK, MonitorCheck::INFO], true)) {
            return $this->resolveActive($check, $run, 'RESOLVED');
        }
        if (! $check->alertable) {
            return [];
        }

        return DB::transaction(function () use ($check, $run): array {
            $changes = [];
            $fingerprint = $check->fingerprint();
            $active = OperationalAlert::query()
                ->where('check_key', $check->key)
                ->whereIn('lifecycle', [OperationalAlert::OPEN, OperationalAlert::ACKNOWLEDGED])
                ->lockForUpdate()->get();
            foreach ($active->where('fingerprint', '!=', $fingerprint) as $obsolete) {
                $this->resolve($obsolete, $run, 'FINGERPRINT_CHANGED');
                $changes[] = ['alert' => $obsolete->fresh(), 'event' => 'RESOLVED'];
            }

            $alert = $active->firstWhere('fingerprint', $fingerprint);
            if ($alert === null) {
                try {
                    $alert = OperationalAlert::query()->create([
                        'check_key' => $check->key,
                        'severity' => $check->severity,
                        'lifecycle' => OperationalAlert::OPEN,
                        'fingerprint' => $fingerprint,
                        'active_dedup_key' => $check->key.'|'.$fingerprint,
                        'summary' => $check->summary,
                        'safe_metadata' => $check->metadata,
                        'occurrence_count' => 1,
                        'first_seen_at' => now(),
                        'last_seen_at' => now(),
                        'row_version' => 1,
                    ]);
                } catch (QueryException) {
                    $alert = OperationalAlert::query()->where('active_dedup_key', $check->key.'|'.$fingerprint)->lockForUpdate()->firstOrFail();
                }
                $this->event($alert, $run, 'OPENED');
                $changes[] = ['alert' => $alert, 'event' => 'OPENED'];

                return $changes;
            }

            $previousSeverity = $alert->severity;
            $occurrences = $alert->occurrence_count + 1;
            $severity = $this->escalatedSeverity($check->severity, $occurrences, $alert->first_seen_at);
            $alert->forceFill([
                'severity' => $this->highest($alert->severity, $severity),
                'summary' => $check->summary,
                'safe_metadata' => $check->metadata,
                'occurrence_count' => $occurrences,
                'last_seen_at' => now(),
                'row_version' => $alert->row_version + 1,
            ])->save();
            $event = $alert->severity !== $previousSeverity ? 'ESCALATED' : 'OCCURRED';
            $this->event($alert, $run, $event);
            $changes[] = ['alert' => $alert, 'event' => $event];

            return $changes;
        }, 3);
    }

    /** @return list<array{alert:OperationalAlert,event:string}> */
    private function resolveActive(MonitorCheck $check, MonitorRun $run, string $reason): array
    {
        return DB::transaction(function () use ($check, $run, $reason): array {
            $alerts = OperationalAlert::query()->where('check_key', $check->key)
                ->whereIn('lifecycle', [OperationalAlert::OPEN, OperationalAlert::ACKNOWLEDGED])
                ->lockForUpdate()->get();
            $changes = [];
            foreach ($alerts as $alert) {
                $this->resolve($alert, $run, $reason, $check);
                $changes[] = ['alert' => $alert, 'event' => 'RESOLVED'];
            }

            return $changes;
        }, 3);
    }

    private function resolve(OperationalAlert $alert, MonitorRun $run, string $reason, ?MonitorCheck $check = null): void
    {
        $alert->forceFill([
            'lifecycle' => OperationalAlert::RESOLVED,
            'active_dedup_key' => null,
            'resolved_at' => now(),
            'last_seen_at' => now(),
            'row_version' => $alert->row_version + 1,
        ])->save();
        $this->event($alert, $run, 'RESOLVED', null, $reason, $check?->metadata ?? []);
    }

    private function event(OperationalAlert $alert, ?MonitorRun $run, string $type, ?int $actorId = null, ?string $note = null, ?array $metadata = null): void
    {
        OperationalAlertEvent::query()->create([
            'operational_alert_id' => $alert->id,
            'monitor_run_id' => $run?->id,
            'actor_user_id' => $actorId,
            'event_type' => $type,
            'severity' => $alert->severity,
            'fingerprint' => $alert->fingerprint,
            'summary' => $alert->summary,
            'safe_metadata' => $metadata ?? $alert->safe_metadata,
            'note' => $note,
            'created_at' => now(),
        ]);
    }

    private function escalatedSeverity(string $severity, int $occurrences, mixed $firstSeen): string
    {
        if ($severity === MonitorCheck::WARN && $occurrences >= max(1, (int) config('operations.monitoring.error_after_occurrences', 3))) {
            $severity = MonitorCheck::ERROR;
        }
        if ($severity === MonitorCheck::ERROR && $firstSeen !== null
            && $firstSeen->diffInMinutes(now()) >= max(1, (int) config('operations.monitoring.critical_after_minutes', 30))) {
            return MonitorCheck::CRITICAL;
        }

        return $severity;
    }

    private function highest(string $left, string $right): string
    {
        $rank = [MonitorCheck::INFO => 0, MonitorCheck::WARN => 1, MonitorCheck::ERROR => 2, MonitorCheck::CRITICAL => 3];

        return ($rank[$right] ?? 0) > ($rank[$left] ?? 0) ? $right : $left;
    }
}
