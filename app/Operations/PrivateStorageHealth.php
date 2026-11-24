<?php

namespace App\Operations;

use App\Backup\PrivateStoragePolicy;
use App\Models\Document;
use App\Models\MessageAttachment;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class PrivateStorageHealth
{
    public function __construct(private readonly PrivateStoragePolicy $policy) {}

    public function check(): MonitorCheck
    {
        try {
            $files = collect(Storage::disk('local')->allFiles())
                ->reject(fn (string $path): bool => $this->policy->isExcludedOperationalArtifact($path))
                ->values();
            $references = collect();
            if (Schema::hasTable('documents')) {
                $references = $references->merge(Document::query()->where('storage_disk', 'local')->pluck('storage_name'));
            }
            if (Schema::hasTable('message_attachments')) {
                $references = $references->merge(MessageAttachment::query()->where('storage_disk', 'local')->pluck('storage_path'));
            }
            $references = $references->filter(fn ($value): bool => is_string($value))->unique()->values();
            $orphans = $files->diff($references)->count();
            $missing = $references->diff($files)->count();
            $severity = $orphans > 0 || $missing > 0 ? MonitorCheck::ERROR : MonitorCheck::OK;

            return new MonitorCheck('private_storage_inventory', $severity, $severity === MonitorCheck::OK
                ? 'Privátní storage odpovídá databázovým referencím.'
                : 'Privátní storage obsahuje agregovanou inventární odchylku.', [
                    'stored_count' => $files->count(),
                    'referenced_count' => $references->count(),
                    'orphan_count' => $orphans,
                    'missing_count' => $missing,
                ]);
        } catch (Throwable) {
            return new MonitorCheck('private_storage_inventory', MonitorCheck::ERROR, 'Inventář privátní storage nelze bezpečně ověřit.', ['check_failed' => true]);
        }
    }
}
