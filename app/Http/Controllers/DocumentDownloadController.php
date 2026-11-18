<?php

namespace App\Http\Controllers;

use App\Models\Document;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentDownloadController extends Controller
{
    public function __invoke(Document $document): StreamedResponse
    {
        abort_unless(auth()->user()?->can('download', $document), 404);
        abort_unless(Storage::disk($document->storage_disk)->exists($document->storage_name), 404);

        return Storage::disk($document->storage_disk)->download(
            $document->storage_name,
            $document->original_name,
            [
                'Content-Type' => $document->mime,
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'private, no-store',
            ],
        );
    }
}
