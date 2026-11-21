<?php

namespace App\Backup;

final class BackupVerification
{
    public function __construct(
        public readonly bool $valid,
        public readonly array $errors,
        public readonly array $warnings,
        public readonly ?array $manifest = null,
        public readonly ?string $root = null,
        public readonly ?string $verificationCode = null,
        public readonly ?string $component = null,
    ) {}

    public function toArray(): array
    {
        return [
            'valid' => $this->valid,
            'errors' => $this->errors,
            'warnings' => $this->warnings,
            'backup_id' => $this->valid ? ($this->manifest['backup_id'] ?? null) : null,
            'status' => $this->valid ? ($this->manifest['status'] ?? null) : null,
            'part_count' => $this->valid ? count($this->manifest['parts'] ?? []) : 0,
            'verification_code' => $this->verificationCode,
            'component' => $this->component,
        ];
    }
}
