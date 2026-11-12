<?php

namespace App\Models;

use App\Enums\ProductConfigurationStatus;
use App\Enums\RoutingCode;
use App\Enums\TechnicalType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LegacyProductConfiguration extends Model
{
    protected $fillable = [
        'product_reference', 'technical_type', 'default_routing',
        'verification_status', 'confirmed_by', 'confirmed_at',
    ];

    protected function casts(): array
    {
        return [
            'technical_type' => TechnicalType::class,
            'default_routing' => RoutingCode::class,
            'verification_status' => ProductConfigurationStatus::class,
            'confirmed_at' => 'immutable_datetime',
        ];
    }

    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }
}
