<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SchedulerHeartbeat extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'scheduler_seen_at' => 'immutable_datetime',
            'monitor_enabled' => 'boolean',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function lastMonitorRun(): BelongsTo
    {
        return $this->belongsTo(MonitorRun::class, 'last_monitor_run_id');
    }
}
