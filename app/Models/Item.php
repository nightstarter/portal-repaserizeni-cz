<?php

namespace App\Models;

use App\Enums\ItemRole;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Item extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id', 'role', 'role_instance', 'qr_token', 'current_location', 'inspection_result',
        'technical_attributes', 'note',
    ];

    protected static function booted(): void
    {
        static::creating(function (Item $item): void {
            $item->qr_token ??= Str::random(48);
        });
    }

    protected function casts(): array
    {
        return [
            'role' => ItemRole::class,
            'technical_attributes' => 'array',
            'role_instance' => 'integer',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function axialRodsSurchargeCzk(): int
    {
        return (bool) ($this->technical_attributes['axial_rods'] ?? false) ? 700 : 0;
    }
}
