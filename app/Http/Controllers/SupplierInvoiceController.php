<?php

namespace App\Http\Controllers;

use App\Application\Documents\DocumentUploadCommand;
use App\Application\Documents\PrivateDocumentService;
use App\Enums\DocumentType;
use App\Exceptions\WorkflowConflictException;
use App\Models\Document;
use App\Models\Order;
use App\Models\ProcessThread;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SupplierInvoiceController extends Controller
{
    public function store(Request $request, Order $order, ProcessThread $thread, PrivateDocumentService $service): RedirectResponse
    {
        return $this->upload($request, $order, $thread, $service, null);
    }

    public function replace(Request $request, Order $order, ProcessThread $thread, Document $document, PrivateDocumentService $service): RedirectResponse
    {
        abort_unless($document->order_id === $order->id && $document->process_thread_id === $thread->id && $document->type === DocumentType::SUPPLIER_INVOICE, 404);

        return $this->upload($request, $order, $thread, $service, $document);
    }

    private function upload(Request $request, Order $order, ProcessThread $thread, PrivateDocumentService $service, ?Document $replaces): RedirectResponse
    {
        abort_unless(request()->user()->can('view', $order), 404);
        abort_unless($thread->order_id === $order->id, 404);
        $validated = $request->validate([
            'file' => ['required', 'file'], 'idempotency_key' => ['required', 'string'],
            'expected_lock_version' => ['required', 'integer', 'min:0'],
            'document_number' => ['required', 'string'], 'document_date' => ['required', 'date'],
            'amount' => ['required', 'string'], 'currency' => ['required', 'string'],
            'deposit_charged' => ['required'], 'deposit_amount' => ['nullable', 'string'],
            'deposit_currency' => ['nullable', 'string'], 'reason' => ['nullable', 'string'],
        ]);
        try {
            $service->uploadInvoice($order, $thread, new DocumentUploadCommand(
                $request->user(), $request->file('file'), $validated['idempotency_key'],
                (int) $validated['expected_lock_version'], $validated['document_number'], $validated['document_date'],
                $validated['amount'], strtoupper($validated['currency']), $request->only(['deposit_charged', 'deposit_amount', 'deposit_currency', 'reason']),
            ), $replaces);
        } catch (WorkflowConflictException $exception) {
            return back()->withErrors(['workflow' => __('app.document_conflict')]);
        }

        return back()->with('status', __('app.document_uploaded'));
    }
}
