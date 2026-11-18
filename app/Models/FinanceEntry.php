<?php

namespace App\Models;

use App\Enums\FinanceEntryStatus;
use App\Enums\FinanceEntryType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinanceEntry extends Model
{
    public const UPDATED_AT = null;

    public const CREATED_AT = 'recorded_at';

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new \LogicException('Finance entries are append-only.');
        });
        static::deleting(static function (): never {
            throw new \LogicException('Finance entries are append-only.');
        });
    }

    protected function casts(): array
    {
        return [
            'type' => FinanceEntryType::class,
            'status' => FinanceEntryStatus::class,
            'amount' => 'decimal:2',
            'recorded_at' => 'immutable_datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function processThread(): BelongsTo
    {
        return $this->belongsTo(ProcessThread::class);
    }

    public function threadEvent(): BelongsTo
    {
        return $this->belongsTo(ThreadEvent::class);
    }

    public function auditLog(): BelongsTo
    {
        return $this->belongsTo(AuditLog::class);
    }

    public function correctsEntry(): BelongsTo
    {
        return $this->belongsTo(self::class, 'corrects_entry_id');
    }

    public function actorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
