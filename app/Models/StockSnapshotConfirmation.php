<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockSnapshotConfirmation extends Model
{
    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(static fn (): never => throw new \LogicException('Stock snapshot confirmations are append-only.'));
        static::deleting(static fn (): never => throw new \LogicException('Stock snapshot confirmations are append-only.'));
    }

    protected function casts(): array
    {
        return [
            'source_generated_at' => 'immutable_datetime',
            'received_at' => 'immutable_datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(StockSnapshotRun::class, 'stock_snapshot_run_id');
    }
}
