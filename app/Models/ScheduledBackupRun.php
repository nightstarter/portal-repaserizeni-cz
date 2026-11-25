<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScheduledBackupRun extends Model
{
    public const SUCCESS = 'SUCCESS';

    public const FAILED = 'FAILED';

    public const SKIPPED_DISABLED = 'SKIPPED_DISABLED';

    public const SKIPPED_LOCKED = 'SKIPPED_LOCKED';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }
}
