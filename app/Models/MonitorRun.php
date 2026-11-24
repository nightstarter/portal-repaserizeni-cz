<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MonitorRun extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'notifications_suppressed' => 'boolean',
            'lock_contended' => 'boolean',
            'safe_results' => 'array',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }

    public function alertEvents(): HasMany
    {
        return $this->hasMany(OperationalAlertEvent::class);
    }
}
