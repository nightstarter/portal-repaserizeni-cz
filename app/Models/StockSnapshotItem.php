<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockSnapshotItem extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(static fn (): never => throw new \LogicException('Stock snapshot items are append-only.'));
        static::deleting(static fn (): never => throw new \LogicException('Stock snapshot items are append-only.'));
    }

    protected function casts(): array
    {
        return ['accepted' => 'boolean', 'available_quantity' => 'integer', 'reserved_quantity' => 'integer'];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(StockSnapshotBatch::class, 'stock_snapshot_batch_id');
    }

    public function productConfiguration(): BelongsTo
    {
        return $this->belongsTo(LegacyProductConfiguration::class);
    }
}
