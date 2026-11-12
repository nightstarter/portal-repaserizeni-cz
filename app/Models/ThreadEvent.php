<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ThreadEvent extends Model
{
    public const UPDATED_AT = null;

    public const CREATED_AT = 'performed_at';

    protected $fillable = [
        'process_thread_id', 'order_id', 'action', 'from_state', 'to_state',
        'actor_type', 'actor_user_id', 'actor_organization_id', 'responsible_organization_id', 'reason',
        'metadata', 'performed_at',
        'idempotency_key', 'request_hash',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'performed_at' => 'immutable_datetime',
        ];
    }

    public function processThread(): BelongsTo
    {
        return $this->belongsTo(ProcessThread::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function actorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function actorOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'actor_organization_id');
    }

    public function responsibleOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'responsible_organization_id');
    }
}
