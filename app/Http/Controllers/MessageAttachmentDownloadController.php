<?php

namespace App\Http\Controllers;

use App\Models\MessageAttachment;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MessageAttachmentDownloadController extends Controller
{
    public function __invoke(MessageAttachment $attachment): StreamedResponse
    {
        abort_unless(auth()->user()?->can('download', $attachment), 404);
        abort_unless(Storage::disk($attachment->storage_disk)->exists($attachment->storage_path), 404);

        return Storage::disk($attachment->storage_disk)->download(
            $attachment->storage_path,
            $attachment->original_name,
            [
                'Content-Type' => $attachment->mime,
                'X-Content-Type-Options' => 'nosniff',
                'Content-Security-Policy' => "default-src 'none'; sandbox",
                'Cache-Control' => 'private, no-store',
            ],
        );
    }
}
