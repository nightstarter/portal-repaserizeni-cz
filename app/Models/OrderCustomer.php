<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderCustomer extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id', 'name', 'company', 'email', 'phone', 'street', 'city',
        'postal_code', 'country_code',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
