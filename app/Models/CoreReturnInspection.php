<?php

namespace App\Models;

use App\Enums\CoreInspectionPieceResult;
use App\Enums\CoreTransportCondition;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CoreReturnInspection extends Model
{
    public const UPDATED_AT = null;

    public const CREATED_AT = 'inspected_at';

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new \LogicException('Core return inspections are append-only.');
        });
        static::deleting(static function (): never {
            throw new \LogicException('Core return inspections are append-only.');
        });
    }

    protected function casts(): array
    {
        return [
            'piece_result' => CoreInspectionPieceResult::class,
            'transport_condition' => CoreTransportCondition::class,
            'accident' => 'boolean',
            'disassembled' => 'boolean',
            'corrosion' => 'boolean',
            'axial_rods' => 'boolean',
            'inspected_at' => 'immutable_datetime',
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

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
