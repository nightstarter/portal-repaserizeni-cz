<?php

namespace App\Http\Controllers;

use App\Application\Documents\DocumentUploadCommand;
use App\Application\Documents\PrivateDocumentService;
use App\Enums\DocumentType;
use App\Enums\ProcessThreadType;
use App\Models\Document;
use App\Models\Order;
use App\Models\ProcessThread;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BuybackInvoiceController extends Controller
{
    public function store(Request $request, Order $order, ProcessThread $thread, PrivateDocumentService $service): JsonResponse
    {
        return $this->upload($request, $order, $thread, $service, null);
    }

    public function replace(Request $request, Order $order, ProcessThread $thread, Document $document, PrivateDocumentService $service): JsonResponse
    {
        abort_unless($document->order_id === $order->id && $document->process_thread_id === $thread->id
            && $document->type === DocumentType::BUYBACK_INVOICE_TO_POLAND, 404);

        return $this->upload($request, $order, $thread, $service, $document);
    }

    private function upload(Request $request, Order $order, ProcessThread $thread, PrivateDocumentService $service, ?Document $replaces): JsonResponse
    {
        $this->authorize('view', $order);
        abort_unless($thread->order_id === $order->id && $thread->type === ProcessThreadType::BUYOUT, 404);
        $validated = $request->validate([
            'file' => ['required', 'file'], 'idempotency_key' => ['required', 'string', 'max:128'],
            'expected_lock_version' => ['required', 'integer', 'min:0'],
            'document_number' => ['required', 'string', 'max:128'], 'document_date' => ['required', 'date'],
            'amount' => ['required', 'string'], 'currency' => ['required', 'string', 'size:3'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);
        $document = $service->uploadBuybackInvoice($order, $thread, new DocumentUploadCommand(
            $request->user(), $request->file('file'), $validated['idempotency_key'],
            (int) $validated['expected_lock_version'], $validated['document_number'], $validated['document_date'],
            $validated['amount'], strtoupper($validated['currency']), ['reason' => $validated['reason'] ?? null],
        ), $replaces);

        return response()->json([
            'document_token' => $document->public_token,
            'download_url' => route('documents.download', $document),
            'state' => $thread->fresh()->state,
        ], $replaces ? 200 : 201);
    }
}
