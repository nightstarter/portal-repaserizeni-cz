<?php

namespace App\Models;

use App\Enums\OrderSource;
use App\Enums\OrderType;
use App\Enums\OrganizationCode;
use App\Enums\ProcessThreadType;
use App\Enums\RoutingCode;
use App\Enums\TechnicalType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_no', 'source', 'source_order_id', 'order_type', 'technical_type',
        'routing', 'product_code', 'product_name_snapshot', 'vin', 'vehicle',
        'customer_handover', 'product_verification_status', 'business_snapshot',
        'imported_at', 'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'source' => OrderSource::class,
            'order_type' => OrderType::class,
            'technical_type' => TechnicalType::class,
            'routing' => RoutingCode::class,
            'business_snapshot' => 'array',
            'imported_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'order_no';
    }

    public function customer(): HasOne
    {
        return $this->hasOne(OrderCustomer::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(Item::class);
    }

    public function processThreads(): HasMany
    {
        return $this->hasMany(ProcessThread::class);
    }

    public function threadEvents(): HasMany
    {
        return $this->hasMany(ThreadEvent::class);
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->organization->code === OrganizationCode::AUTOKLUBY) {
            return $query;
        }

        return $query->where(function (Builder $visible) use ($user): void {
            $visible
                ->whereHas('processThreads', fn (Builder $threads) => $threads->where('responsible_organization_id', $user->organization_id))
                ->orWhereHas('threadEvents', fn (Builder $events) => $events->where(function (Builder $history) use ($user): void {
                    $history
                        ->where('responsible_organization_id', $user->organization_id)
                        ->orWhere('actor_organization_id', $user->organization_id);
                }));
        });
    }

    public function scopeCustomRebuildVisibleTo(Builder $query, User $user): Builder
    {
        $query->where('order_type', OrderType::CUSTOM_REBUILD);

        if ($user->organization->code === OrganizationCode::AUTOKLUBY) {
            return $query;
        }

        return $query->whereHas('processThreads', function (Builder $threads) use ($user): void {
            $threads
                ->where('type', ProcessThreadType::CUSTOM_REBUILD)
                ->where(function (Builder $access) use ($user): void {
                    $access
                        ->where('responsible_organization_id', $user->organization_id)
                        ->orWhereHas('events', fn (Builder $events) => $events->where(function (Builder $history) use ($user): void {
                            $history
                                ->where('responsible_organization_id', $user->organization_id)
                                ->orWhere('actor_organization_id', $user->organization_id);
                        }));
                });
        });
    }
}
