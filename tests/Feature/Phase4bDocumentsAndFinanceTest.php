<?php

namespace Tests\Feature;

use App\Application\Documents\DocumentUploadCommand;
use App\Application\Documents\PrivateDocumentService;
use App\Application\Orders\CoreReturnCommand;
use App\Application\Orders\CoreReturnWorkflow;
use App\Enums\CoreReturnAction;
use App\Enums\CoreReturnState;
use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Enums\FinanceEntryType;
use App\Enums\OrganizationCode;
use App\Enums\ProcessThreadType;
use App\Enums\ResponsibleParty;
use App\Enums\UserRole;
use App\Exceptions\WorkflowConflictException;
use App\Models\Document;
use App\Models\FinanceEntry;
use App\Models\Order;
use App\Models\Organization;
use App\Models\ProcessThread;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class Phase4bDocumentsAndFinanceTest extends TestCase
{
    use RefreshDatabase;

    private Organization $ak;

    private Organization $pl;

    private User $akAdmin;

    private User $akOperator;

    private User $plOperator;

    private PrivateDocumentService $documents;

    private CoreReturnWorkflow $core;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
        $this->ak = Organization::factory()->create(['code' => OrganizationCode::AUTOKLUBY, 'name' => 'AUTOKLUBY']);
        $this->pl = Organization::factory()->create(['code' => OrganizationCode::POLAND, 'name' => 'POLSKO']);
        $this->akAdmin = User::factory()->create(['organization_id' => $this->ak->id, 'role' => UserRole::AK_ADMIN]);
        $this->akOperator = User::factory()->create(['organization_id' => $this->ak->id, 'role' => UserRole::AK_OPERATOR]);
        $this->plOperator = User::factory()->create(['organization_id' => $this->pl->id, 'role' => UserRole::PL_OPERATOR, 'preferred_locale' => 'pl']);
        $this->documents = app(PrivateDocumentService::class);
        $this->core = app(CoreReturnWorkflow::class);
    }

    public function test_pdf_jpeg_and_png_are_private_randomly_named_and_checksummed(): void
    {
        foreach (['pdf', 'jpg', 'png'] as $extension) {
            [$order, $business] = $this->order('safe-'.$extension);
            $file = $this->validFile('invoice.'.$extension, $extension);
            $expectedChecksum = hash_file('sha256', $file->getRealPath());
            $document = $this->documents->uploadInvoice($order, $business, $this->invoiceCommand($business, $file, 'safe-'.$extension, false));

            self::assertSame($expectedChecksum, $document->checksum_sha256);
            self::assertNotSame($file->getClientOriginalName(), basename($document->storage_name));
            self::assertStringStartsWith('documents/', $document->storage_name);
            self::assertDoesNotMatchRegularExpression('/invoice/i', basename($document->storage_name));
            Storage::disk('local')->assertExists($document->storage_name);
            self::assertSame([], Storage::disk('public')->allFiles());
        }
    }

    public function test_size_mime_executable_double_extension_and_traversal_are_rejected(): void
    {
        [$order, $business] = $this->order('unsafe');
        config()->set('documents.max_kilobytes', 1);
        $cases = [
            UploadedFile::fake()->create('large.pdf', 2, 'application/pdf'),
            $this->realFile('spoof.pdf', '<?php echo 1;', 'application/pdf'),
            UploadedFile::fake()->createWithContent('payload.php', '<?php echo 1;'),
            UploadedFile::fake()->createWithContent('page.html', '<html></html>'),
            UploadedFile::fake()->createWithContent('vector.svg', '<svg></svg>'),
            UploadedFile::fake()->createWithContent('invoice.php.pdf', '%PDF-1.4'),
        ];
        foreach ($cases as $index => $file) {
            try {
                $this->documents->uploadInvoice($order, $business->fresh(), $this->invoiceCommand($business->fresh(), $file, 'unsafe-'.$index, false));
                self::fail('Expected unsafe upload rejection for case '.$index.'.');
            } catch (ValidationException) {
                self::assertSame(0, Document::query()->count());
            }
        }
        $path = tempnam(sys_get_temp_dir(), 'upload');
        file_put_contents($path, '%PDF-1.4');
        $traversal = new UploadedFile($path, '../invoice.pdf', 'application/pdf', null, true);
        $safeTraversal = $this->documents->uploadInvoice($order, $business, $this->invoiceCommand($business, $traversal, 'traversal', false));
        self::assertStringNotContainsString('..', $safeTraversal->storage_name);
        self::assertStringNotContainsString('invoice', basename($safeTraversal->storage_name));
        self::assertSame('invoice.pdf', $safeTraversal->original_name);
    }

    public function test_invoice_without_and_with_deposit_use_shared_immutable_ledger(): void
    {
        [$firstOrder, $firstBusiness, $firstCore] = $this->order('without');
        $this->documents->uploadInvoice($firstOrder, $firstBusiness, $this->invoiceCommand($firstBusiness, $this->validFile(), 'without', false));
        self::assertSame('1500.00', $firstCore->financeEntries()->where('type', FinanceEntryType::SUPPLIER_PURCHASE_PRICE)->sole()->amount);
        self::assertSame(1, $firstCore->financeEntries()->where('type', FinanceEntryType::SUPPLIER_DEPOSIT_NOT_CHARGED)->count());
        self::assertSame(0, $firstCore->financeEntries()->where('type', FinanceEntryType::SUPPLIER_DEPOSIT_ORIGINAL)->count());

        [$secondOrder, $secondBusiness, $secondCore] = $this->order('with');
        $this->documents->uploadInvoice($secondOrder, $secondBusiness, $this->invoiceCommand($secondBusiness, $this->validFile(), 'with', true, '400.50'));
        $deposit = $secondCore->financeEntries()->where('type', FinanceEntryType::SUPPLIER_DEPOSIT_ORIGINAL)->sole();
        self::assertSame('400.50', $deposit->amount);
        self::assertSame('PLN', $deposit->currency);
        $this->assertDatabaseHas('audit_log', ['action' => 'SUPPLIER_INVOICE_UPLOADED']);
    }

    public function test_invoice_requires_deposit_amount_and_matching_currency(): void
    {
        [$order, $business] = $this->order('deposit-validation');
        foreach ([
            ['deposit_charged' => true],
            ['deposit_charged' => true, 'deposit_amount' => '100.00', 'deposit_currency' => 'EUR'],
        ] as $index => $data) {
            $command = $this->invoiceCommand($business, $this->validFile(), 'deposit-invalid-'.$index, false);
            $command = new DocumentUploadCommand($command->actor, $command->file, $command->idempotencyKey, $command->expectedLockVersion, $command->documentNumber, $command->documentDate, $command->amount, $command->currency, $data);
            try {
                $this->documents->uploadInvoice($order, $business, $command);
                self::fail('Expected deposit validation failure.');
            } catch (ValidationException) {
                self::assertSame(0, Document::query()->count());
                self::assertSame(0, FinanceEntry::query()->count());
            }
        }
    }

    public function test_invoice_replacement_invalidates_original_and_appends_reversals(): void
    {
        [$order, $business] = $this->order('replace');
        $original = $this->documents->uploadInvoice($order, $business, $this->invoiceCommand($business, $this->validFile(), 'original', true, '300.00'));
        $replacement = $this->documents->uploadInvoice($order, $business->fresh(), new DocumentUploadCommand(
            $this->plOperator, $this->validFile('replacement.pdf'), 'replacement', $business->fresh()->lock_version,
            'FV-NEW', '2026-11-18', '1600.00', 'PLN', ['deposit_charged' => true, 'deposit_amount' => '350.00', 'reason' => 'Opravená faktura'],
        ), $original);
        self::assertSame(DocumentStatus::INVALIDATED, $original->fresh()->status);
        self::assertSame(DocumentStatus::ACTIVE, $replacement->status);
        self::assertSame(1, Document::query()->whereNotNull('active_key')->count());
        self::assertSame(2, FinanceEntry::query()->where('type', FinanceEntryType::REVERSAL)->count());
        self::assertSame('1500.00', FinanceEntry::query()->where('type', FinanceEntryType::SUPPLIER_PURCHASE_PRICE)->oldest('id')->first()->amount);
    }

    public function test_reversed_invoice_terms_cannot_drive_a_later_deposit_decision(): void
    {
        [$noDepositOrder, $noDepositBusiness, $noDepositCore] = $this->order('replace-to-no-deposit');
        $withDeposit = $this->documents->uploadInvoice($noDepositOrder, $noDepositBusiness, $this->invoiceCommand($noDepositBusiness, $this->validFile(), 'terms-with', true, '300.00'));
        $this->documents->uploadInvoice($noDepositOrder, $noDepositBusiness->fresh(), new DocumentUploadCommand(
            $this->plOperator, $this->validFile('without-deposit.pdf'), 'terms-to-without', $noDepositBusiness->fresh()->lock_version,
            'FV-WITHOUT', '2026-11-18', '1500.00', 'PLN', ['deposit_charged' => false, 'reason' => 'Záloha nebyla účtována'],
        ), $withDeposit);

        self::assertFalse($noDepositCore->financeEntries()->effective()->where('type', FinanceEntryType::SUPPLIER_DEPOSIT_ORIGINAL)->exists());
        self::assertTrue($noDepositCore->financeEntries()->effective()->where('type', FinanceEntryType::SUPPLIER_DEPOSIT_NOT_CHARGED)->exists());
        try {
            $this->core->execute($noDepositOrder, new CoreReturnCommand(CoreReturnAction::APPROVE_FULL_DEPOSIT_RETURN, $this->plOperator, 'reversed-deposit-must-not-approve', [], $noDepositCore->fresh()->lock_version));
            self::fail('A reversed deposit must not authorize a return.');
        } catch (WorkflowConflictException) {
            self::assertSame(CoreReturnState::CORE_INSPECTION->value, $noDepositCore->fresh()->state);
        }
        $this->core->execute($noDepositOrder, new CoreReturnCommand(CoreReturnAction::CONFIRM_NO_SUPPLIER_DEPOSIT, $this->plOperator, 'effective-no-deposit', [], $noDepositCore->fresh()->lock_version));
        self::assertSame(CoreReturnState::CORE_SETTLED_NO_DEPOSIT->value, $noDepositCore->fresh()->state);

        [$depositOrder, $depositBusiness, $depositCore] = $this->order('replace-to-deposit');
        $withoutDeposit = $this->documents->uploadInvoice($depositOrder, $depositBusiness, $this->invoiceCommand($depositBusiness, $this->validFile(), 'terms-without', false));
        $this->documents->uploadInvoice($depositOrder, $depositBusiness->fresh(), new DocumentUploadCommand(
            $this->plOperator, $this->validFile('with-deposit.pdf'), 'terms-to-with', $depositBusiness->fresh()->lock_version,
            'FV-WITH', '2026-11-18', '1500.00', 'PLN', ['deposit_charged' => true, 'deposit_amount' => '300.00', 'reason' => 'Doplněna účtovaná záloha'],
        ), $withoutDeposit);

        self::assertFalse($depositCore->financeEntries()->effective()->where('type', FinanceEntryType::SUPPLIER_DEPOSIT_NOT_CHARGED)->exists());
        self::assertTrue($depositCore->financeEntries()->effective()->where('type', FinanceEntryType::SUPPLIER_DEPOSIT_ORIGINAL)->exists());
        try {
            $this->core->execute($depositOrder, new CoreReturnCommand(CoreReturnAction::CONFIRM_NO_SUPPLIER_DEPOSIT, $this->plOperator, 'reversed-no-deposit-must-not-settle', [], $depositCore->fresh()->lock_version));
            self::fail('Reversed no-deposit evidence must not settle the return.');
        } catch (WorkflowConflictException) {
            self::assertSame(CoreReturnState::CORE_INSPECTION->value, $depositCore->fresh()->state);
        }
        $this->core->execute($depositOrder, new CoreReturnCommand(CoreReturnAction::APPROVE_FULL_DEPOSIT_RETURN, $this->plOperator, 'effective-deposit', [], $depositCore->fresh()->lock_version));
        self::assertSame(CoreReturnState::DEPOSIT_FULL_APPROVED->value, $depositCore->fresh()->state);
    }

    public function test_download_is_tokenized_authorized_attached_nosniff_and_invalidated_is_hidden(): void
    {
        [$order, $business] = $this->order('download');
        $document = $this->documents->uploadInvoice($order, $business, $this->invoiceCommand($business, $this->validFile('safe.pdf'), 'download', false));
        self::assertFalse(ctype_digit($document->getRouteKey()));
        $this->actingAs($this->plOperator)->get(route('documents.download', $document))
            ->assertOk()->assertHeader('content-disposition', 'attachment; filename=safe.pdf')
            ->assertHeader('x-content-type-options', 'nosniff');
        $hiddenOrder = Order::factory()->stockRebuild()->create();
        $hiddenThread = ProcessThread::factory()->create(['order_id' => $hiddenOrder->id, 'type' => ProcessThreadType::STOCK_REQUEST, 'responsible_party' => ResponsibleParty::AUTOKLUBY, 'responsible_organization_id' => $this->ak->id]);
        Storage::disk('local')->put('documents/hidden.pdf', '%PDF-1.4');
        $hidden = Document::query()->create([
            'public_token' => fake()->uuid(), 'order_id' => $hiddenOrder->id, 'process_thread_id' => $hiddenThread->id,
            'type' => DocumentType::SUPPLIER_INVOICE, 'storage_disk' => 'local', 'storage_name' => 'documents/hidden.pdf',
            'original_name' => 'hidden.pdf', 'mime' => 'application/pdf', 'extension' => 'pdf', 'size' => 8,
            'checksum_sha256' => hash('sha256', '%PDF-1.4'), 'document_number' => 'HIDDEN', 'document_date' => '2026-11-18',
            'amount' => '1.00', 'currency' => 'PLN', 'uploaded_by' => $this->akAdmin->id,
            'uploaded_organization_id' => $this->ak->id, 'status' => DocumentStatus::ACTIVE,
        ]);
        $this->actingAs($this->plOperator)->get(route('documents.download', $hidden))->assertNotFound();
        $this->actingAs($this->akOperator)->get('/documents/'.str_repeat('0', 36).'/download')->assertNotFound();
        $document->update(['status' => DocumentStatus::INVALIDATED, 'active_key' => null]);
        $this->actingAs($this->akAdmin)->get(route('documents.download', $document))->assertNotFound();
    }

    public function test_storage_failure_writes_nothing_and_database_failure_removes_orphan(): void
    {
        [$order, $business] = $this->order('storage-fail');
        config()->set('filesystems.disks.failing', ['driver' => 'local', 'root' => '/proc/portal-documents-unwritable', 'throw' => false]);
        config()->set('documents.disk', 'failing');
        try {
            $this->documents->uploadInvoice($order, $business, $this->invoiceCommand($business, $this->validFile(), 'storage-fail', false));
            self::fail('Expected storage failure.');
        } catch (ValidationException) {
            self::assertSame(0, Document::query()->count());
            self::assertSame(0, FinanceEntry::query()->count());
        }
        config()->set('documents.disk', 'local');
        Storage::fake('local');

        [$secondOrder, $secondBusiness] = $this->order('db-fail');
        DB::listen(static function ($query): void {
            if (str_starts_with(strtolower(ltrim($query->sql)), 'insert into "finance_entries"')) {
                throw new \RuntimeException('simulated DB failure');
            }
        });
        try {
            $this->documents->uploadInvoice($secondOrder, $secondBusiness, $this->invoiceCommand($secondBusiness, $this->validFile(), 'db-fail', false));
            self::fail('Expected database failure.');
        } catch (\RuntimeException) {
            self::assertSame(0, Document::query()->count());
            self::assertSame([], Storage::disk('local')->allFiles());
        }
    }

    public function test_full_and_reduced_credit_notes_validate_amount_currency_and_complete_only_after_payment(): void
    {
        foreach ([['full', '500.00'], ['reduced', '300.00']] as [$kind, $approved]) {
            [$order, $business, $core] = $this->order('credit-'.$kind);
            $this->documents->uploadInvoice($order, $business, $this->invoiceCommand($business, $this->validFile(), 'invoice-'.$kind, true, '500.00'));
            $core = $core->fresh();
            $action = $kind === 'full' ? CoreReturnAction::APPROVE_FULL_DEPOSIT_RETURN : CoreReturnAction::APPROVE_REDUCED_DEPOSIT_RETURN;
            $data = $kind === 'full' ? [] : ['approved_return_amount' => $approved, 'currency' => 'PLN', 'reason' => 'Krácení'];
            $this->core->execute($order, new CoreReturnCommand($action, $this->plOperator, 'decision-'.$kind, $data, $core->lock_version));
            $core = $core->fresh();
            $credit = $this->documents->uploadCreditNote($order, $core, $this->creditCommand($core, $this->validFile('credit.pdf'), 'credit-'.$kind, $approved));
            self::assertSame(CoreReturnState::CREDIT_ISSUED->value, $core->fresh()->state);
            self::assertSame(DocumentType::SUPPLIER_CREDIT_NOTE, $credit->type);
            self::assertNull($core->fresh()->completed_at);
            $version = $core->fresh()->lock_version;
            $command = new CoreReturnCommand(CoreReturnAction::CONFIRM_CREDIT_PAID, $this->plOperator, 'paid-'.$kind, [], $version);
            $first = $this->core->execute($order, $command);
            $second = $this->core->execute($order, $command);
            self::assertSame($first->id, $second->id);
            self::assertSame(CoreReturnState::CREDIT_PAID->value, $core->fresh()->state);
            self::assertNotNull($core->fresh()->completed_at);
            self::assertSame(1, $core->financeEntries()->where('type', FinanceEntryType::SUPPLIER_CREDIT_PAID)->count());
        }
    }

    public function test_credit_note_rejects_wrong_amount_currency_no_decision_and_no_deposit_branch(): void
    {
        [$order, $business, $core] = $this->order('credit-invalid');
        $this->documents->uploadInvoice($order, $business, $this->invoiceCommand($business, $this->validFile(), 'invoice-invalid', true, '500.00'));
        foreach ([['400.00', 'PLN'], ['500.00', 'EUR']] as $index => [$amount, $currency]) {
            try {
                $this->documents->uploadCreditNote($order, $core->fresh(), $this->creditCommand($core->fresh(), $this->validFile('credit.pdf'), 'wrong-'.$index, $amount, $currency));
                self::fail('Expected credit note rejection.');
            } catch (ValidationException|WorkflowConflictException) {
                self::assertSame(0, Document::query()->where('type', DocumentType::SUPPLIER_CREDIT_NOTE)->count());
            }
        }
        [$noOrder, $noBusiness, $noCore] = $this->order('credit-none');
        $this->documents->uploadInvoice($noOrder, $noBusiness, $this->invoiceCommand($noBusiness, $this->validFile(), 'invoice-none', false));
        $this->core->execute($noOrder, new CoreReturnCommand(CoreReturnAction::CONFIRM_NO_SUPPLIER_DEPOSIT, $this->plOperator, 'settle-none', [], $noCore->fresh()->lock_version));
        $this->expectException(ValidationException::class);
        $this->documents->uploadCreditNote($noOrder, $noCore->fresh(), $this->creditCommand($noCore->fresh(), $this->validFile('credit.pdf'), 'none-credit', '0.00'));
    }

    public function test_payment_requires_active_credit_and_other_active_branch_keeps_order_open(): void
    {
        [$order, , $core] = $this->order('payment-required');
        try {
            $this->core->execute($order, new CoreReturnCommand(CoreReturnAction::CONFIRM_CREDIT_PAID, $this->plOperator, 'paid-without', [], $core->lock_version));
            self::fail('Expected invalid state/payment rejection.');
        } catch (WorkflowConflictException) {
            self::assertNull($order->fresh()->closed_at);
        }
    }

    public function test_credit_note_replacement_is_versioned_and_uses_reversal_entries(): void
    {
        [$order, $business, $core] = $this->order('credit-replace');
        $this->documents->uploadInvoice($order, $business, $this->invoiceCommand($business, $this->validFile(), 'credit-replace-invoice', true, '500.00'));
        $this->core->execute($order, new CoreReturnCommand(CoreReturnAction::APPROVE_FULL_DEPOSIT_RETURN, $this->plOperator, 'credit-replace-decision', [], $core->fresh()->lock_version));
        $original = $this->documents->uploadCreditNote($order, $core->fresh(), $this->creditCommand($core->fresh(), $this->validFile('credit-original.pdf'), 'credit-original', '500.00'));
        $replacement = $this->documents->replaceCreditNote($order, $core->fresh(), $original, new DocumentUploadCommand(
            $this->plOperator, $this->validFile('credit-corrected.pdf'), 'credit-corrected', $core->fresh()->lock_version,
            'DN-CORRECTED', '2026-11-18', '500.00', 'PLN', ['reason' => 'Oprava čísla dobropisu'],
        ));

        self::assertSame(DocumentStatus::INVALIDATED, $original->fresh()->status);
        self::assertSame(DocumentStatus::ACTIVE, $replacement->status);
        self::assertSame($original->id, $replacement->replaces_document_id);
        self::assertSame(CoreReturnState::CREDIT_ISSUED->value, $core->fresh()->state);
        self::assertSame(1, $core->financeEntries()->where('type', FinanceEntryType::REVERSAL)->where('corrects_entry_id', '!=', null)->count());
        self::assertSame(2, $core->financeEntries()->where('type', FinanceEntryType::SUPPLIER_CREDIT_NOTE_ISSUED)->count());
        $this->assertDatabaseHas('audit_log', ['action' => 'SUPPLIER_CREDIT_NOTE_REPLACED', 'reason' => 'Oprava čísla dobropisu']);
    }

    public function test_invoice_permissions_and_admin_override_reason_are_enforced(): void
    {
        [$order, $business] = $this->order('permissions');
        $operatorCommand = new DocumentUploadCommand(
            $this->akOperator, $this->validFile(), 'ak-operator-denied', $business->lock_version,
            'FV-DENIED', '2026-11-18', '10.00', 'PLN', ['deposit_charged' => false],
        );
        try {
            $this->documents->uploadInvoice($order, $business, $operatorCommand);
            self::fail('AK operator must not change supplier finance.');
        } catch (AuthorizationException) {
            self::assertSame(0, Document::query()->count());
        }

        $adminWithoutReason = new DocumentUploadCommand(
            $this->akAdmin, $this->validFile(), 'admin-no-reason', $business->lock_version,
            'FV-ADMIN', '2026-11-18', '10.00', 'PLN', ['deposit_charged' => false],
        );
        try {
            $this->documents->uploadInvoice($order, $business, $adminWithoutReason);
            self::fail('AK_ADMIN override reason is mandatory.');
        } catch (ValidationException) {
            self::assertSame(0, Document::query()->count());
        }

        $admin = new DocumentUploadCommand(
            $this->akAdmin, $this->validFile(), 'admin-reason', $business->lock_version,
            'FV-ADMIN', '2026-11-18', '10.00', 'PLN', ['deposit_charged' => false, 'reason' => 'Kontrolovaná oprava'],
        );
        $document = $this->documents->uploadInvoice($order, $business, $admin);
        self::assertSame($this->akAdmin->id, $document->uploaded_by);
        $this->assertDatabaseHas('audit_log', ['action' => 'SUPPLIER_INVOICE_UPLOADED', 'reason' => 'Kontrolovaná oprava']);
    }

    public function test_poland_core_return_ui_has_safe_forms_and_hides_customer_data(): void
    {
        [$order, $business, $core] = $this->order('ui');
        $order->customer()->create([
            'name' => 'Tajný zákazník', 'email' => 'tajny@example.test', 'phone' => '+420111222333',
            'street' => 'Soukromá 1', 'city' => 'Praha', 'postal_code' => '10000',
        ]);
        $this->documents->uploadInvoice($order, $business, $this->invoiceCommand($business, $this->validFile(), 'ui-invoice', true, '500.00'));
        $this->core->execute($order, new CoreReturnCommand(CoreReturnAction::APPROVE_FULL_DEPOSIT_RETURN, $this->plOperator, 'ui-decision', [], $core->fresh()->lock_version));

        $this->actingAs($this->plOperator)->get(route('orders.show', $order))
            ->assertOk()
            ->assertSee('name="_token"', false)
            ->assertSee('name="idempotency_key"', false)
            ->assertSee('name="expected_lock_version"', false)
            ->assertSee('Prześlij notę kredytową')
            ->assertDontSee('Tajný zákazník')
            ->assertDontSee('tajny@example.test')
            ->assertDontSee('+420111222333');

        $this->actingAs($this->plOperator)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Noty do przesłania');
    }

    private function order(string $suffix): array
    {
        $order = Order::factory()->stockRebuild()->create(['order_no' => '4B-'.$suffix, 'source_order_id' => '4B-'.$suffix]);
        $business = ProcessThread::factory()->create([
            'order_id' => $order->id, 'type' => ProcessThreadType::STOCK_REQUEST, 'state' => 'WAITING_PL_STOCK',
            'responsible_party' => ResponsibleParty::POLAND, 'responsible_organization_id' => $this->pl->id,
        ]);
        $core = ProcessThread::factory()->create([
            'order_id' => $order->id, 'type' => ProcessThreadType::CORE_RETURN, 'state' => CoreReturnState::CORE_INSPECTION,
            'responsible_party' => ResponsibleParty::POLAND, 'responsible_organization_id' => $this->pl->id,
        ]);

        return [$order, $business, $core];
    }

    private function invoiceCommand(ProcessThread $thread, UploadedFile $file, string $key, bool $deposit, ?string $depositAmount = null): DocumentUploadCommand
    {
        return new DocumentUploadCommand(
            $this->plOperator, $file, $key, $thread->fresh()->lock_version,
            'FV-'.$key, '2026-11-18', '1500.00', 'PLN',
            ['deposit_charged' => $deposit, 'deposit_amount' => $depositAmount],
        );
    }

    private function creditCommand(ProcessThread $thread, UploadedFile $file, string $key, string $amount, string $currency = 'PLN'): DocumentUploadCommand
    {
        return new DocumentUploadCommand($this->plOperator, $file, $key, $thread->fresh()->lock_version, 'DN-'.$key, '2026-11-18', $amount, $currency);
    }

    private function validFile(string $name = 'invoice.pdf', string $extension = 'pdf'): UploadedFile
    {
        return match ($extension) {
            'jpg' => UploadedFile::fake()->image($name, 10, 10),
            'png' => UploadedFile::fake()->image($name, 10, 10),
            default => UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF"),
        };
    }

    private function realFile(string $name, string $contents, string $mime): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'document-test');
        file_put_contents($path, $contents);

        return new UploadedFile($path, $name, $mime, null, true);
    }
}
