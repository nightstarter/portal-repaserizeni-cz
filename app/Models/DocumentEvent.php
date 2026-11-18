<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentEvent extends Model
{
    public const UPDATED_AT = null;

    public const CREATED_AT = 'performed_at';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'performed_at' => 'immutable_datetime'];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
