<?php

namespace App\Models;

use App\Enums\ProcessThreadType;
use App\Enums\ResponsibleParty;
use App\Enums\RoutingCode;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProcessThread extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id', 'type', 'state', 'responsible_party',
        'responsible_organization_id', 'vendor_routing', 'warranty_cycle', 'eta',
        'completed_at', 'lock_version',
    ];

    protected function casts(): array
    {
        return [
            'type' => ProcessThreadType::class,
            'responsible_party' => ResponsibleParty::class,
            'vendor_routing' => RoutingCode::class,
            'eta' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'lock_version' => 'integer',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function responsibleOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'responsible_organization_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(ThreadEvent::class);
    }
}
