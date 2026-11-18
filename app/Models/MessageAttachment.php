<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MessageAttachment extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new \LogicException('Message attachments are append-only.');
        });
        static::deleting(static function (): never {
            throw new \LogicException('Message attachments are append-only.');
        });
    }

    protected function casts(): array
    {
        return ['size' => 'integer', 'created_at' => 'immutable_datetime'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_token';
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }
}
