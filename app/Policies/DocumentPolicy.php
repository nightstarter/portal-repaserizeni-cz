<?php

namespace App\Policies;

use App\Enums\DocumentStatus;
use App\Enums\OrganizationCode;
use App\Models\Document;
use App\Models\ProcessThread;
use App\Models\User;

class DocumentPolicy
{
    public function download(User $user, Document $document): bool
    {
        if (! $user->active || ! $user->organization->active || $document->status !== DocumentStatus::ACTIVE) {
            return false;
        }
        if ($user->organization->code === OrganizationCode::AUTOKLUBY) {
            return true;
        }

        return $document->process_thread_id !== null
            && ProcessThread::query()->visibleTo($user)->whereKey($document->process_thread_id)->exists();
    }
}
