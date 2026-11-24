<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OperationalAlert extends Model
{
    public const OPEN = 'OPEN';
    public const ACKNOWLEDGED = 'ACKNOWLEDGED';
    public const RESOLVED = 'RESOLVED';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'safe_metadata' => 'array',
            'first_seen_at' => 'immutable_datetime',
            'last_seen_at' => 'immutable_datetime',
            'acknowledged_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
            'last_notified_at' => 'immutable_datetime',
        ];
    }

    public function events(): HasMany
    {
        return $this->hasMany(OperationalAlertEvent::class)->orderBy('id');
    }

    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }
}
