<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BridgeCommand extends Model
{
    public const PENDING = 'PENDING';

    public const PROCESSING = 'PROCESSING';

    public const SUCCEEDED = 'SUCCEEDED';

    public const FAILED = 'FAILED';

    protected $guarded = ['id'];

    public function getRouteKeyName(): string
    {
        return 'command_id';
    }

    protected function casts(): array
    {
        return [
            'payload' => 'array', 'attempts' => 'integer',
            'available_at' => 'immutable_datetime', 'lease_until' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }

    public function results(): HasMany
    {
        return $this->hasMany(BridgeCommandResult::class);
    }
}
