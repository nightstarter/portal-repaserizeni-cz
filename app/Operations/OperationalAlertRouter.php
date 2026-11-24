<?php

namespace App\Operations;

use App\Mail\OperationalAlertMail;
use App\Models\OperationalAlert;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

final class OperationalAlertRouter
{
    /** @param list<array{alert:OperationalAlert,event:string}> $changes */
    public function route(array $changes, bool $noNotify): void
    {
        foreach ($changes as $change) {
            $alert = $change['alert'];
            $event = $change['event'];
            Log::log($this->logLevel($alert->severity), 'operational_alert', [
                'alert_id' => $alert->id,
                'check_key' => $alert->check_key,
                'severity' => $alert->severity,
                'lifecycle' => $alert->lifecycle,
                'event' => $event,
                'fingerprint' => $alert->fingerprint,
                'occurrence_count' => $alert->occurrence_count,
            ]);
            if (! $noNotify) {
                $this->email($alert, $event);
            }
        }
    }

    private function email(OperationalAlert $alert, string $event): void
    {
        if (! config('operations.monitoring.email_enabled')) {
            return;
        }
        $recipients = (array) config('operations.monitoring.email_recipients', []);
        if ($recipients === []) {
            return;
        }
        $candidate = in_array($event, ['OPENED', 'ESCALATED', 'RESOLVED'], true)
            || ($event === 'OCCURRED' && in_array($alert->severity, [MonitorCheck::ERROR, MonitorCheck::CRITICAL], true));
        if (! $candidate || ($event === 'RESOLVED' && $alert->last_notified_state === 'RESOLVED')) {
            return;
        }
        $cooldown = max(1, (int) config('operations.monitoring.cooldown_minutes', 30));
        if ($event === 'OCCURRED' && $alert->last_notified_at?->isAfter(now()->subMinutes($cooldown))) {
            return;
        }
        try {
            Mail::to($recipients)->send(new OperationalAlertMail(
                $alert->check_key,
                $alert->severity,
                $alert->lifecycle,
                $event,
                $alert->summary,
            ));
            $alert->forceFill(['last_notified_at' => now(), 'last_notified_state' => $event])->save();
        } catch (Throwable) {
            Log::error('operational_alert_delivery_failed', [
                'alert_id' => $alert->id,
                'check_key' => $alert->check_key,
                'channel' => 'email',
                'code' => 'ALERT_EMAIL_DELIVERY_FAILED',
            ]);
        }
    }

    private function logLevel(string $severity): string
    {
        return match ($severity) {
            MonitorCheck::CRITICAL => 'critical',
            MonitorCheck::ERROR => 'error',
            MonitorCheck::WARN => 'warning',
            default => 'info',
        };
    }
}
