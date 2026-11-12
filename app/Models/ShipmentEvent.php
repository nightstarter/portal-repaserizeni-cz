<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShipmentEvent extends Model
{
    public const CREATED_AT = 'performed_at';

    public const UPDATED_AT = null;

    protected $fillable = [
        'shipment_id', 'action', 'idempotency_key', 'request_hash', 'from_status',
        'to_status', 'actor_user_id', 'actor_organization_id', 'reason', 'metadata',
        'performed_at',
    ];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'performed_at' => 'immutable_datetime'];
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function actorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function actorOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'actor_organization_id');
    }
}
