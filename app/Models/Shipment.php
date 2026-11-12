<?php

namespace App\Models;

use App\Enums\ShipmentCarrier;
use App\Enums\ShipmentDirection;
use App\Enums\ShipmentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Shipment extends Model
{
    use HasFactory;

    protected $fillable = [
        'public_number', 'direction', 'carrier', 'tracking', 'status',
        'sender_organization_id', 'recipient_organization_id', 'created_by',
        'sent_at', 'delivered_at', 'transport_error_text', 'lock_version',
    ];

    protected static function booted(): void
    {
        static::creating(function (Shipment $shipment): void {
            $shipment->public_number ??= 'SHP-'.Str::upper(Str::random(26));
        });
    }

    protected function casts(): array
    {
        return [
            'direction' => ShipmentDirection::class,
            'carrier' => ShipmentCarrier::class,
            'status' => ShipmentStatus::class,
            'sent_at' => 'immutable_datetime',
            'delivered_at' => 'immutable_datetime',
            'lock_version' => 'integer',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_number';
    }

    public function senderOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'sender_organization_id');
    }

    public function recipientOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'recipient_organization_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function shipmentItems(): HasMany
    {
        return $this->hasMany(ShipmentItem::class);
    }

    public function items(): BelongsToMany
    {
        return $this->belongsToMany(Item::class, 'shipment_items')
            ->withPivot('process_thread_id')
            ->withTimestamps();
    }

    public function events(): HasMany
    {
        return $this->hasMany(ShipmentEvent::class);
    }
}
