<?php

namespace App\Policies;

use App\Models\MessageAttachment;
use App\Models\User;

class MessageAttachmentPolicy
{
    public function download(User $user, MessageAttachment $attachment): bool
    {
        return $user->can('view', $attachment->message);
    }
}
