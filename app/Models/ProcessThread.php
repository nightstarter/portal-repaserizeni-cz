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

class ProcessThread extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id', 'type', 'type_instance', 'state', 'responsible_party',
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
            'type_instance' => 'integer',
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

    public function responsibleOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'responsible_organization_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(ThreadEvent::class);
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
