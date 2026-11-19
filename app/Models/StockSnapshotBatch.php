<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockSnapshotBatch extends Model
{
    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(static fn (): never => throw new \LogicException('Stock snapshot batches are append-only.'));
        static::deleting(static fn (): never => throw new \LogicException('Stock snapshot batches are append-only.'));
    }

    protected function casts(): array
    {
        return [
            'source_generated_at' => 'immutable_datetime',
            'received_at' => 'immutable_datetime',
            'accepted_count' => 'integer',
            'rejected_count' => 'integer',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockSnapshotItem::class);
    }
}
