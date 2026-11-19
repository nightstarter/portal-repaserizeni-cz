<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BridgeCommandResult extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(static fn (): never => throw new \LogicException('Bridge command results are append-only.'));
        static::deleting(static fn (): never => throw new \LogicException('Bridge command results are append-only.'));
    }

    protected function casts(): array
    {
        return ['metadata' => 'array', 'completed_at' => 'immutable_datetime', 'received_at' => 'immutable_datetime'];
    }

    public function command(): BelongsTo
    {
        return $this->belongsTo(BridgeCommand::class, 'bridge_command_id');
    }
}
