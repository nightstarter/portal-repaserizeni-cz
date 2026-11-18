<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Message extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new \LogicException('Messages are append-only.');
        });
        static::deleting(static function (): never {
            throw new \LogicException('Messages are append-only.');
        });
    }

    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime'];
    }

    public function scopeUnreadFor(Builder $query, User $user): Builder
    {
        return $query
            ->where('author_user_id', '!=', $user->id)
            ->whereDoesntHave('reads', fn (Builder $reads) => $reads->where('user_id', $user->id));
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }

    public function authorOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'author_organization_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(MessageAttachment::class);
    }

    public function reads(): HasMany
    {
        return $this->hasMany(MessageRead::class);
    }
}
