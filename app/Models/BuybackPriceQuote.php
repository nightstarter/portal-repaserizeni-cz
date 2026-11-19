<?php

namespace App\Models;

use App\Enums\BuybackPriceType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BuybackPriceQuote extends Model
{
    public const UPDATED_AT = null;

    public const CREATED_AT = 'recorded_at';

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(static fn (): never => throw new \LogicException('Buyback price history is append-only.'));
        static::deleting(static fn (): never => throw new \LogicException('Buyback price history is append-only.'));
    }

    protected function casts(): array
    {
        return [
            'type' => BuybackPriceType::class,
            'amount_minor' => 'integer',
            'version' => 'integer',
            'recorded_at' => 'immutable_datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function processThread(): BelongsTo
    {
        return $this->belongsTo(ProcessThread::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
