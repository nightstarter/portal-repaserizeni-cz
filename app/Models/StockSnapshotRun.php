<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockSnapshotRun extends Model
{
    public const RECEIVING = 'RECEIVING';

    public const COMPLETE = 'COMPLETE';

    public const PARTIAL = 'PARTIAL';

    public const FAILED = 'FAILED';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'source_generated_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'chunk_count' => 'integer',
            'received_chunks' => 'integer',
            'accepted_count' => 'integer',
            'rejected_count' => 'integer',
        ];
    }

    public function batches(): HasMany
    {
        return $this->hasMany(StockSnapshotBatch::class);
    }
}
