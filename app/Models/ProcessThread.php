<?php

namespace App\Models;

use App\Enums\CustomRebuildState;
use App\Enums\OrganizationCode;
use App\Enums\ProcessThreadType;
use App\Enums\ResponsibleParty;
use App\Enums\RoutingCode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ProcessThread extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id', 'item_id', 'type', 'type_instance', 'state', 'responsible_party',
        'responsible_organization_id', 'vendor_routing', 'warranty_cycle', 'eta',
        'created_by', 'creation_idempotency_key', 'creation_request_hash', 'completed_at', 'lock_version',
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
            'type_instance' => 'integer',
            'warranty_cycle' => 'integer',
        ];
    }

    public function customRebuildState(): CustomRebuildState
    {
        return CustomRebuildState::from($this->state);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function responsibleOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'responsible_organization_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(ThreadEvent::class);
    }

    public function latestEvent(): HasOne
    {
        return $this->hasOne(ThreadEvent::class)->ofMany([
            'performed_at' => 'max',
            'id' => 'max',
        ]);
    }

    public function shipmentItems(): HasMany
    {
        return $this->hasMany(ShipmentItem::class);
    }

    public function coreReturnInspections(): HasMany
    {
        return $this->hasMany(CoreReturnInspection::class);
    }

    public function financeEntries(): HasMany
    {
        return $this->hasMany(FinanceEntry::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    public function buybackPriceQuotes(): HasMany
    {
        return $this->hasMany(BuybackPriceQuote::class);
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->organization->code === OrganizationCode::AUTOKLUBY) {
            return $query;
        }

        return $query
            ->whereIn('type', [
                ProcessThreadType::CUSTOM_REBUILD,
                ProcessThreadType::STOCK_REQUEST,
                ProcessThreadType::STOCK_REPLENISHMENT,
                ProcessThreadType::CORE_RETURN,
                ProcessThreadType::WARRANTY,
                ProcessThreadType::BUYOUT,
            ])
            ->where(function (Builder $access) use ($user): void {
                $access
                    ->where('responsible_organization_id', $user->organization_id)
                    ->orWhereHas('events', fn (Builder $events) => $events->where(function (Builder $history) use ($user): void {
                        $history
                            ->where('responsible_organization_id', $user->organization_id)
                            ->orWhere('actor_organization_id', $user->organization_id);
                    }));
            });
    }
}
