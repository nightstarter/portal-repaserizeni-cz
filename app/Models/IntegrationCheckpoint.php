<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IntegrationCheckpoint extends Model
{
    protected $fillable = [
        'integration', 'cursor', 'last_processed_id', 'last_processed_at', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'last_processed_at' => 'immutable_datetime',
            'metadata' => 'array',
        ];
    }
}
