<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BridgeNonce extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['used_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime'];
    }
}
