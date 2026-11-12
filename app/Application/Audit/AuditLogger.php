<?php

namespace App\Application\Audit;

use App\Models\AuditLog;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

final class AuditLogger
{
    private const SENSITIVE_KEYS = ['password', 'password_confirmation', 'remember_token', 'secret', 'token'];

    public function record(
        string $action,
        ?Model $subject = null,
        array $oldValues = [],
        array $newValues = [],
        ?string $reason = null,
        ?User $actor = null,
        ?Request $request = null,
    ): AuditLog {
        $actor ??= auth()->user();
        $request ??= request();

        return AuditLog::query()->create([
            'actor_user_id' => $actor?->id,
            'organization_id' => $actor?->organization_id,
            'order_id' => $subject instanceof Order ? $subject->id : $subject?->getAttribute('order_id'),
            'subject_type' => $subject ? $subject::class : null,
            'subject_id' => $subject?->getKey(),
            'action' => $action,
            'old_values' => $this->filter($oldValues),
            'new_values' => $this->filter($newValues),
            'reason' => $reason,
            'ip_address' => $request?->ip(),
            'user_agent' => mb_substr((string) $request?->userAgent(), 0, 500),
        ]);
    }

    private function filter(array $values): array
    {
        foreach ($values as $key => $value) {
            if (in_array(strtolower((string) $key), self::SENSITIVE_KEYS, true)) {
                $values[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $values[$key] = $this->filter($value);
            }
        }

        return $values;
    }
}
