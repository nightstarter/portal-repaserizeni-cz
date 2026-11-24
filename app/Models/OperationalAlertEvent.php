<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OperationalAlertEvent extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['safe_metadata' => 'array', 'created_at' => 'immutable_datetime'];
    }

    public function alert(): BelongsTo
    {
        return $this->belongsTo(OperationalAlert::class, 'operational_alert_id');
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(MonitorRun::class, 'monitor_run_id');
    }
}
