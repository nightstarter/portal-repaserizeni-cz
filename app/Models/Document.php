<?php

namespace App\Models;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Document extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'type' => DocumentType::class,
            'status' => DocumentStatus::class,
            'amount' => 'decimal:2',
            'document_date' => 'immutable_date',
            'invalidated_at' => 'immutable_datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_token';
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function processThread(): BelongsTo
    {
        return $this->belongsTo(ProcessThread::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function uploadedOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'uploaded_organization_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(DocumentEvent::class);
    }

    public function replacesDocument(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaces_document_id');
    }
}
