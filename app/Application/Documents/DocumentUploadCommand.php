<?php

namespace App\Application\Documents;

use App\Models\User;
use Illuminate\Http\UploadedFile;

final readonly class DocumentUploadCommand
{
    public function __construct(
        public User $actor,
        public UploadedFile $file,
        public string $idempotencyKey,
        public int $expectedLockVersion,
        public string $documentNumber,
        public string $documentDate,
        public string $amount,
        public string $currency,
        public array $data = [],
    ) {}
}
