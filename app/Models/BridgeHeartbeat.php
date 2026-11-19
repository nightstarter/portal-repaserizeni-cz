<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BridgeHeartbeat extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(static fn (): never => throw new \LogicException('Bridge heartbeats are append-only.'));
        static::deleting(static fn (): never => throw new \LogicException('Bridge heartbeats are append-only.'));
    }

    protected function casts(): array
    {
        return [
            'occurred_at' => 'immutable_datetime',
            'last_pohoda_read_at' => 'immutable_datetime',
            'last_snapshot_at' => 'immutable_datetime',
            'last_command_poll_at' => 'immutable_datetime',
            'received_at' => 'immutable_datetime',
        ];
    }
}
