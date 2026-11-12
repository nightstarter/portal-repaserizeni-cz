<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IntegrationEvent extends Model
{
    protected $fillable = [
        'integration', 'external_event_id', 'type', 'payload_hash', 'payload',
        'payload_reference', 'status', 'attempts', 'error', 'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'attempts' => 'integer',
            'processed_at' => 'immutable_datetime',
        ];
    }
}
