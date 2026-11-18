<?php

namespace App\Application\Documents;

use App\Application\Audit\AuditLogger;
use App\Application\Finance\FinancialLedgerService;
use App\Application\Orders\CoreReturnCommand;
use App\Application\Orders\CoreReturnWorkflow;
use App\Enums\CoreReturnAction;
use App\Enums\CoreReturnState;
use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Enums\FinanceEntryStatus;
use App\Enums\FinanceEntryType;
use App\Enums\OrganizationCode;
use App\Enums\ProcessThreadType;
use App\Enums\UserRole;
use App\Exceptions\WorkflowConflictException;
use App\Models\Document;
use App\Models\DocumentEvent;
use App\Models\FinanceEntry;
use App\Models\Order;
use App\Models\ProcessThread;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class PrivateDocumentService
{
    public function __construct(
        private readonly FinancialLedgerService $ledger,
        private readonly AuditLogger $auditLogger,
        private readonly CoreReturnWorkflow $coreWorkflow,
    ) {}

    public function uploadInvoice(Order $order, ProcessThread $businessThread, DocumentUploadCommand $command, ?Document $replaces = null): Document
    {
        $override = $this->authorizeInvoice($businessThread, $command, $replaces);
        $validated = $this->validateUpload($command);
        $depositCharged = filter_var($command->data['deposit_charged'] ?? null, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        if ($depositCharged === null) {
            throw ValidationException::withMessages(['deposit_charged' => 'An explicit supplier deposit choice is required.']);
        }
        $deposit = null;
        if ($depositCharged) {
            $deposit = $this->normalizeAmount((string) ($command->data['deposit_amount'] ?? ''), false, 'deposit_amount');
            if (($command->data['deposit_currency'] ?? $command->currency) !== $command->currency) {
                throw ValidationException::withMessages(['deposit_currency' => 'Supplier deposit currency must match the invoice.']);
            }
        } elseif (array_key_exists('deposit_amount', $command->data) && trim((string) $command->data['deposit_amount']) !== '') {
            throw ValidationException::withMessages(['deposit_amount' => 'Deposit amount is forbidden when no supplier deposit was charged.']);
        }
        $reason = trim((string) ($command->data['reason'] ?? ''));
        if (($replaces !== null || $override) && $reason === '') {
            throw ValidationException::withMessages(['reason' => 'A replacement or administrator override requires a reason.']);
        }
        $payload = $validated + ['deposit_charged' => $depositCharged, 'deposit_amount' => $deposit, 'reason' => $reason];
        $hash = $this->requestHash('SUPPLIER_INVOICE', $command, $payload);
        if (($existing = $this->existing($command->idempotencyKey, $hash)) !== null) {
            return $existing;
        }

        [$disk, $path] = $this->store($command, $validated['extension']);
        try {
            return DB::transaction(function () use ($order, $businessThread, $command, $replaces, $override, $payload, $hash, $disk, $path): Document {
                if (($existing = $this->existing($command->idempotencyKey, $hash)) !== null) {
                    Storage::disk($disk)->delete($path);

                    return $existing;
                }
                $lockedOrder = Order::query()->lockForUpdate()->findOrFail($order->id);
                $thread = ProcessThread::query()->whereKey($businessThread->id)->where('order_id', $lockedOrder->id)->lockForUpdate()->sole();
                $this->assertVersion($thread, $command);
                $activeKey = 'invoice:'.$thread->id;
                $active = Document::query()->where('active_key', $activeKey)->lockForUpdate()->first();
                if ($replaces === null && $active !== null) {
                    throw new WorkflowConflictException('This business branch already has an active supplier invoice.');
                }
                if ($replaces !== null && ($active === null || $active->id !== $replaces->id || $replaces->status !== DocumentStatus::ACTIVE)) {
                    throw new WorkflowConflictException('The invoice being replaced is not the active version.');
                }
                if ($replaces !== null) {
                    $replaces->update([
                        'status' => DocumentStatus::INVALIDATED,
                        'active_key' => null,
                        'invalidation_reason' => $payload['reason'],
                        'invalidated_at' => now(),
                        'invalidated_by' => $command->actor->id,
                    ]);
                }
                $document = $this->createDocument($lockedOrder, $thread, DocumentType::SUPPLIER_INVOICE, $command, $payload, $disk, $path, $activeKey, $replaces);
                [$event, $auditId] = $this->recordBusinessEvent($lockedOrder, $thread, $document, $command, $hash, $replaces ? 'SUPPLIER_INVOICE_REPLACED' : 'SUPPLIER_INVOICE_UPLOADED', $payload['reason'] ?: null, $override);
                $financeThread = ProcessThread::query()->where('order_id', $lockedOrder->id)->where('type', ProcessThreadType::CORE_RETURN)->lockForUpdate()->first() ?? $thread;
                $entries = [];
                if ($replaces !== null) {
                    $oldEventId = data_get($replaces->events()->latest('id')->first()?->metadata, 'thread_event_id');
                    FinanceEntry::query()->where('thread_event_id', $oldEventId)
                        ->whereIn('type', [FinanceEntryType::SUPPLIER_PURCHASE_PRICE, FinanceEntryType::SUPPLIER_DEPOSIT_ORIGINAL, FinanceEntryType::SUPPLIER_DEPOSIT_NOT_CHARGED])
                        ->orderBy('id')->get()->each(function (FinanceEntry $old) use (&$entries, $payload): void {
                            $entries[] = [
                                'type' => FinanceEntryType::REVERSAL,
                                'amount' => $this->negate($old->amount),
                                'currency' => $old->currency,
                                'status' => FinanceEntryStatus::REVERSED,
                                'reason' => $payload['reason'],
                                'corrects_entry_id' => $old->id,
                            ];
                        });
                }
                $ledgerReason = $payload['reason'] ?: 'SUPPLIER_INVOICE_DOCUMENT:'.$document->id;
                $entries[] = ['type' => FinanceEntryType::SUPPLIER_PURCHASE_PRICE, 'amount' => $payload['amount'], 'currency' => $payload['currency'], 'status' => FinanceEntryStatus::CONFIRMED, 'reason' => $ledgerReason];
                $entries[] = $payload['deposit_charged']
                    ? ['type' => FinanceEntryType::SUPPLIER_DEPOSIT_ORIGINAL, 'amount' => $payload['deposit_amount'], 'currency' => $payload['currency'], 'status' => FinanceEntryStatus::CONFIRMED, 'reason' => $ledgerReason]
                    : ['type' => FinanceEntryType::SUPPLIER_DEPOSIT_NOT_CHARGED, 'amount' => '0.00', 'currency' => $payload['currency'], 'status' => FinanceEntryStatus::CONFIRMED, 'reason' => $ledgerReason.':NO_SUPPLIER_DEPOSIT'];
                $this->ledger->append($lockedOrder, $financeThread, $event, $auditId, $command->actor, 'doc-finance:'.$command->idempotencyKey, $hash, $entries);
                $this->recordDocumentEvent($document, $command, $hash, $replaces ? 'REPLACED' : 'UPLOADED', $payload['reason'] ?: null, ['thread_event_id' => $event->id, 'admin_override' => $override]);

                return $document;
            }, 3);
        } catch (\Throwable $exception) {
            Storage::disk($disk)->delete($path);
            throw $exception;
        }
    }

    public function uploadCreditNote(Order $order, ProcessThread $coreThread, DocumentUploadCommand $command): Document
    {
        $override = $this->authorizeCoreFinance($coreThread, $command);
        $validated = $this->validateUpload($command);
        $reason = trim((string) ($command->data['reason'] ?? ''));
        if ($override && $reason === '') {
            throw ValidationException::withMessages(['reason' => 'Administrator override requires a reason.']);
        }
        $approved = $coreThread->financeEntries()->effective()->where('type', FinanceEntryType::SUPPLIER_DEPOSIT_RETURN_APPROVED)->latest('id')->first();
        if ($approved === null || $validated['amount'] !== $approved->amount || $validated['currency'] !== $approved->currency) {
            throw ValidationException::withMessages(['amount' => 'Credit note must exactly match the approved supplier deposit return.']);
        }
        $payload = $validated + ['reason' => $reason];
        $hash = $this->requestHash('SUPPLIER_CREDIT_NOTE', $command, $payload);
        if (($existing = $this->existing($command->idempotencyKey, $hash)) !== null) {
            return $existing;
        }
        [$disk, $path] = $this->store($command, $validated['extension']);
        try {
            return DB::transaction(function () use ($order, $coreThread, $command, $payload, $hash, $disk, $path, $override): Document {
                $lockedOrder = Order::query()->lockForUpdate()->findOrFail($order->id);
                $thread = ProcessThread::query()->whereKey($coreThread->id)->where('type', ProcessThreadType::CORE_RETURN)->lockForUpdate()->sole();
                $this->assertVersion($thread, $command);
                if (! in_array(CoreReturnState::from($thread->state), [CoreReturnState::DEPOSIT_FULL_APPROVED, CoreReturnState::DEPOSIT_REDUCED_APPROVED], true)) {
                    throw new WorkflowConflictException('A supplier deposit return decision is required before a credit note.');
                }
                $activeKey = 'credit:'.$thread->id;
                if (Document::query()->where('active_key', $activeKey)->exists()) {
                    throw new WorkflowConflictException('This settlement already has an active credit note.');
                }
                $document = $this->createDocument($lockedOrder, $thread, DocumentType::SUPPLIER_CREDIT_NOTE, $command, $payload, $disk, $path, $activeKey, null);
                $event = $this->coreWorkflow->execute($lockedOrder, new CoreReturnCommand(
                    CoreReturnAction::ISSUE_SUPPLIER_CREDIT_NOTE,
                    $command->actor,
                    'core-credit:'.$command->idempotencyKey,
                    ['document_id' => $document->id, 'amount' => $payload['amount'], 'currency' => $payload['currency']] + ($override ? ['override_reason' => $payload['reason']] : []),
                    $thread->lock_version,
                ));
                $this->recordDocumentEvent($document, $command, $hash, 'UPLOADED', $payload['reason'] ?: null, ['thread_event_id' => $event->id, 'admin_override' => $override]);
                $this->auditLogger->record('SUPPLIER_CREDIT_NOTE_UPLOADED', $document, [], ['thread_event_id' => $event->id, 'checksum' => $document->checksum_sha256], $payload['reason'] ?: null, $command->actor);

                return $document;
            }, 3);
        } catch (\Throwable $exception) {
            Storage::disk($disk)->delete($path);
            throw $exception;
        }
    }

    public function replaceCreditNote(Order $order, ProcessThread $coreThread, Document $replaces, DocumentUploadCommand $command): Document
    {
        $override = $this->authorizeCoreFinance($coreThread, $command);
        $validated = $this->validateUpload($command);
        $reason = trim((string) ($command->data['reason'] ?? ''));
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'A credit-note replacement requires a reason.']);
        }
        $approved = $coreThread->financeEntries()->effective()->where('type', FinanceEntryType::SUPPLIER_DEPOSIT_RETURN_APPROVED)->latest('id')->first();
        if ($approved === null || $validated['amount'] !== $approved->amount || $validated['currency'] !== $approved->currency) {
            throw ValidationException::withMessages(['amount' => 'Credit note must exactly match the approved supplier deposit return.']);
        }
        $payload = $validated + ['reason' => $reason];
        $hash = $this->requestHash('REPLACE_SUPPLIER_CREDIT_NOTE', $command, $payload);
        if (($existing = $this->existing($command->idempotencyKey, $hash)) !== null) {
            return $existing;
        }
        [$disk, $path] = $this->store($command, $validated['extension']);
        try {
            return DB::transaction(function () use ($order, $coreThread, $replaces, $command, $payload, $hash, $disk, $path, $override): Document {
                if (($existing = $this->existing($command->idempotencyKey, $hash)) !== null) {
                    Storage::disk($disk)->delete($path);

                    return $existing;
                }
                $lockedOrder = Order::query()->lockForUpdate()->findOrFail($order->id);
                $thread = ProcessThread::query()->whereKey($coreThread->id)->where('type', ProcessThreadType::CORE_RETURN)->lockForUpdate()->sole();
                $this->assertVersion($thread, $command);
                if (CoreReturnState::from($thread->state) !== CoreReturnState::CREDIT_ISSUED) {
                    throw new WorkflowConflictException('Only an issued, unpaid credit note may be replaced.');
                }
                $activeKey = 'credit:'.$thread->id;
                $active = Document::query()->where('active_key', $activeKey)->lockForUpdate()->first();
                if ($active === null || $active->id !== $replaces->id || $replaces->status !== DocumentStatus::ACTIVE) {
                    throw new WorkflowConflictException('The credit note being replaced is not the active version.');
                }
                $replaces->update([
                    'status' => DocumentStatus::INVALIDATED,
                    'active_key' => null,
                    'invalidation_reason' => $payload['reason'],
                    'invalidated_at' => now(),
                    'invalidated_by' => $command->actor->id,
                ]);
                $document = $this->createDocument($lockedOrder, $thread, DocumentType::SUPPLIER_CREDIT_NOTE, $command, $payload, $disk, $path, $activeKey, $replaces);
                [$event, $auditId] = $this->recordBusinessEvent($lockedOrder, $thread, $document, $command, $hash, 'SUPPLIER_CREDIT_NOTE_REPLACED', $payload['reason'], $override);
                $oldEntry = $thread->financeEntries()->effective()->where('type', FinanceEntryType::SUPPLIER_CREDIT_NOTE_ISSUED)->latest('id')->firstOrFail();
                $this->ledger->append($lockedOrder, $thread, $event, $auditId, $command->actor, 'doc-finance:'.$command->idempotencyKey, $hash, [[
                    'type' => FinanceEntryType::REVERSAL,
                    'amount' => $this->negate($oldEntry->amount),
                    'currency' => $oldEntry->currency,
                    'status' => FinanceEntryStatus::REVERSED,
                    'reason' => $payload['reason'],
                    'corrects_entry_id' => $oldEntry->id,
                ], [
                    'type' => FinanceEntryType::SUPPLIER_CREDIT_NOTE_ISSUED,
                    'amount' => $payload['amount'],
                    'currency' => $payload['currency'],
                    'status' => FinanceEntryStatus::CONFIRMED,
                    'reason' => 'SUPPLIER_CREDIT_NOTE_DOCUMENT:'.$document->id,
                ]]);
                $this->recordDocumentEvent($document, $command, $hash, 'REPLACED', $payload['reason'], ['thread_event_id' => $event->id, 'admin_override' => $override]);

                return $document;
            }, 3);
        } catch (\Throwable $exception) {
            Storage::disk($disk)->delete($path);
            throw $exception;
        }
    }

    private function authorizeInvoice(ProcessThread $thread, DocumentUploadCommand $command, ?Document $replaces): bool
    {
        if (! in_array($thread->type, [ProcessThreadType::CUSTOM_REBUILD, ProcessThreadType::STOCK_REQUEST, ProcessThreadType::STOCK_REPLENISHMENT], true)) {
            throw new AuthorizationException;
        }
        if ($command->actor->organization->code === OrganizationCode::POLAND
            && in_array($command->actor->role, [UserRole::PL_MANAGER, UserRole::PL_OPERATOR], true)
            && ProcessThread::query()->visibleTo($command->actor)->whereKey($thread->id)->exists()) {
            return false;
        }
        if ($command->actor->organization->code === OrganizationCode::AUTOKLUBY && $command->actor->role === UserRole::AK_ADMIN) {
            return true;
        }
        throw new AuthorizationException;
    }

    private function authorizeCoreFinance(ProcessThread $thread, DocumentUploadCommand $command): bool
    {
        if ($thread->type !== ProcessThreadType::CORE_RETURN) {
            throw new AuthorizationException;
        }
        if ($command->actor->organization->code === OrganizationCode::POLAND
            && in_array($command->actor->role, [UserRole::PL_MANAGER, UserRole::PL_OPERATOR], true)
            && ProcessThread::query()->visibleTo($command->actor)->whereKey($thread->id)->exists()) {
            return false;
        }
        if ($command->actor->organization->code === OrganizationCode::AUTOKLUBY && $command->actor->role === UserRole::AK_ADMIN) {
            return true;
        }
        throw new AuthorizationException;
    }

    private function validateUpload(DocumentUploadCommand $command): array
    {
        Validator::make([
            'idempotency_key' => $command->idempotencyKey,
            'expected_lock_version' => $command->expectedLockVersion,
            'document_number' => $command->documentNumber,
            'document_date' => $command->documentDate,
            'currency' => $command->currency,
        ], [
            'idempotency_key' => ['required', 'string', 'max:120'],
            'expected_lock_version' => ['required', 'integer', 'min:0'],
            'document_number' => ['required', 'string', 'max:128'],
            'document_date' => ['required', 'date'],
            'currency' => ['required', 'regex:/^[A-Z]{3}$/'],
        ])->validate();
        if (! $command->file->isValid()) {
            throw ValidationException::withMessages(['file' => 'The upload failed.']);
        }
        $original = $command->file->getClientOriginalName();
        if (basename(str_replace('\\', '/', $original)) !== $original || str_contains($original, '..') || substr_count($original, '.') !== 1) {
            throw ValidationException::withMessages(['file' => 'Unsafe or double-extension filename.']);
        }
        $extension = strtolower($command->file->getClientOriginalExtension());
        $allowed = config('documents.allowed', []);
        if (! isset($allowed[$extension])) {
            throw ValidationException::withMessages(['file' => 'This document extension is not allowed.']);
        }
        if ($command->file->getSize() > ((int) config('documents.max_kilobytes', 10240) * 1024)) {
            throw ValidationException::withMessages(['file' => 'The document exceeds the configured size limit.']);
        }
        $actualMime = $command->file->getMimeType();
        $declaredMime = $command->file->getClientMimeType();
        if (! in_array($actualMime, $allowed[$extension], true) || ! in_array($declaredMime, $allowed[$extension], true)) {
            throw ValidationException::withMessages(['file' => 'Document extension, declared MIME and actual MIME must agree.']);
        }
        $safeName = preg_replace('/[^\pL\pN._ -]+/u', '_', $original) ?: 'document.'.$extension;

        return [
            'original_name' => mb_substr($safeName, 0, 255),
            'extension' => $extension,
            'mime' => $actualMime,
            'size' => $command->file->getSize(),
            'checksum_sha256' => hash_file('sha256', $command->file->getRealPath()),
            'document_number' => trim($command->documentNumber),
            'document_date' => $command->documentDate,
            'amount' => $this->normalizeAmount($command->amount, false, 'amount'),
            'currency' => $command->currency,
        ];
    }

    private function store(DocumentUploadCommand $command, string $extension): array
    {
        $disk = (string) config('documents.disk', 'local');
        $path = 'documents/'.now()->format('Y/m').'/'.Str::uuid().'.'.$extension;
        $stream = fopen($command->file->getRealPath(), 'rb');
        try {
            $stored = $stream !== false && Storage::disk($disk)->put($path, $stream);
        } catch (\Throwable) {
            $stored = false;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
        if (! $stored) {
            throw ValidationException::withMessages(['file' => 'The private document could not be stored.']);
        }

        return [$disk, $path];
    }

    private function createDocument(Order $order, ProcessThread $thread, DocumentType $type, DocumentUploadCommand $command, array $data, string $disk, string $path, string $activeKey, ?Document $replaces): Document
    {
        return Document::query()->create([
            'public_token' => (string) Str::uuid(), 'order_id' => $order->id, 'process_thread_id' => $thread->id,
            'type' => $type, 'storage_disk' => $disk, 'storage_name' => $path,
            'original_name' => $data['original_name'], 'mime' => $data['mime'], 'extension' => $data['extension'],
            'size' => $data['size'], 'checksum_sha256' => $data['checksum_sha256'],
            'document_number' => $data['document_number'], 'document_date' => $data['document_date'],
            'amount' => $data['amount'], 'currency' => $data['currency'], 'uploaded_by' => $command->actor->id,
            'uploaded_organization_id' => $command->actor->organization_id, 'status' => DocumentStatus::ACTIVE,
            'active_key' => $activeKey, 'replaces_document_id' => $replaces?->id,
        ]);
    }

    private function recordBusinessEvent(Order $order, ProcessThread $thread, Document $document, DocumentUploadCommand $command, string $hash, string $action, ?string $reason, bool $override): array
    {
        $oldVersion = $thread->lock_version;
        if (ProcessThread::query()->whereKey($thread->id)->where('lock_version', $oldVersion)->update(['lock_version' => $oldVersion + 1, 'updated_at' => now()]) !== 1) {
            throw new WorkflowConflictException('Process thread version conflict.');
        }
        $event = $thread->events()->create([
            'order_id' => $order->id, 'action' => $action,
            'idempotency_key' => 'thread-doc:'.$command->idempotencyKey, 'request_hash' => $hash,
            'from_state' => $thread->state, 'to_state' => $thread->state,
            'actor_type' => $command->actor->organization->code->value,
            'actor_user_id' => $command->actor->id, 'actor_organization_id' => $command->actor->organization_id,
            'responsible_organization_id' => $thread->responsible_organization_id, 'reason' => $reason,
            'metadata' => ['document_id' => $document->id, 'document_type' => $document->type->value, 'admin_override' => $override],
        ]);
        $audit = $this->auditLogger->record($action, $document, [], ['thread_event_id' => $event->id, 'checksum' => $document->checksum_sha256], $reason, $command->actor);

        return [$event, $audit->id];
    }

    private function recordDocumentEvent(Document $document, DocumentUploadCommand $command, string $hash, string $action, ?string $reason, array $metadata): DocumentEvent
    {
        return DocumentEvent::query()->create([
            'document_id' => $document->id, 'order_id' => $document->order_id, 'action' => $action,
            'idempotency_key' => $command->idempotencyKey, 'request_hash' => $hash,
            'actor_user_id' => $command->actor->id, 'actor_organization_id' => $command->actor->organization_id,
            'reason' => $reason, 'metadata' => $metadata,
        ]);
    }

    private function existing(string $key, string $hash): ?Document
    {
        $event = DocumentEvent::query()->where('idempotency_key', $key)->first();
        if ($event === null) {
            return null;
        }
        if ($event->request_hash !== $hash) {
            throw new WorkflowConflictException('Document idempotency key was reused with a different payload.');
        }

        return $event->document;
    }

    private function assertVersion(ProcessThread $thread, DocumentUploadCommand $command): void
    {
        if ($thread->lock_version !== $command->expectedLockVersion) {
            throw new WorkflowConflictException('Process thread version conflict.');
        }
    }

    private function requestHash(string $action, DocumentUploadCommand $command, array $payload): string
    {
        ksort($payload);

        return hash('sha256', json_encode(['action' => $action, 'actor' => $command->actor->id, 'payload' => $payload, 'version' => $command->expectedLockVersion], JSON_THROW_ON_ERROR));
    }

    private function normalizeAmount(string $amount, bool $zero, string $field): string
    {
        if (! preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]{1,2})?$/', $amount)) {
            throw ValidationException::withMessages([$field => 'A non-negative decimal with at most two fraction digits is required.']);
        }
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');
        $minor = ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
        if (! $zero && $minor < 1) {
            throw ValidationException::withMessages([$field => 'Amount must be positive.']);
        }

        return intdiv($minor, 100).'.'.str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }

    private function negate(string $amount): string
    {
        return str_starts_with($amount, '-') ? substr($amount, 1) : '-'.$amount;
    }
}
