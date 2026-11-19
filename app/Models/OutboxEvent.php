<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OutboxEvent extends Model
{
    public const PENDING = 'PENDING';

    public const PROCESSING = 'PROCESSING';

    public const SENT = 'SENT';

    public const FAILED = 'FAILED';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'attempts' => 'integer',
            'available_at' => 'immutable_datetime',
            'next_attempt_at' => 'immutable_datetime',
            'locked_at' => 'immutable_datetime',
            'processed_at' => 'immutable_datetime',
            'sent_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
        ];
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'aggregate_id');
    }

    public function buybackThread(): BelongsTo
    {
        return $this->belongsTo(ProcessThread::class, 'aggregate_id');
    }
}
