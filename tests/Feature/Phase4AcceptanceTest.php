<?php

namespace Tests\Feature;

use App\Application\Documents\DocumentUploadCommand;
use App\Application\Documents\PrivateDocumentService;
use App\Application\Orders\CoreReturnCommand;
use App\Application\Orders\CoreReturnWorkflow;
use App\Application\Shipments\ShipmentCommand;
use App\Application\Shipments\ShipmentService;
use App\Enums\CoreInspectionPieceResult;
use App\Enums\CoreReturnAction;
use App\Enums\CoreReturnState;
use App\Enums\FinanceEntryType;
use App\Enums\ItemRole;
use App\Enums\OrganizationCode;
use App\Enums\ProcessThreadType;
use App\Enums\ResponsibleParty;
use App\Enums\ShipmentCarrier;
use App\Enums\ShipmentDirection;
use App\Enums\UserRole;
use App\Exceptions\WorkflowConflictException;
use App\Models\Item;
use App\Models\Order;
use App\Models\Organization;
use App\Models\ProcessThread;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\IsolatedStorageSandbox;
use Tests\TestCase;

class Phase4AcceptanceTest extends TestCase
{
    use RefreshDatabase;

    private Organization $ak;

    private Organization $pl;

    private User $akOperator;

    private User $plOperator;

    private CoreReturnWorkflow $core;

    private ShipmentService $shipments;

    private PrivateDocumentService $documents;

    private ?IsolatedStorageSandbox $storageSandbox = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storageSandbox = IsolatedStorageSandbox::create();
        Storage::set('local', Storage::build(array_replace(
            config('filesystems.disks.local'),
            ['root' => $this->storageSandbox->diskRoot('private')],
        )));
        Storage::set('public', Storage::build(array_replace(
            config('filesystems.disks.public'),
            ['root' => $this->storageSandbox->diskRoot('public')],
        )));
        $this->ak = Organization::factory()->create(['code' => OrganizationCode::AUTOKLUBY, 'name' => 'AUTOKLUBY']);
        $this->pl = Organization::factory()->create(['code' => OrganizationCode::POLAND, 'name' => 'POLSKO']);
        $this->akOperator = User::factory()->create(['organization_id' => $this->ak->id, 'role' => UserRole::AK_OPERATOR]);
        $this->plOperator = User::factory()->create(['organization_id' => $this->pl->id, 'role' => UserRole::PL_OPERATOR]);
        $this->core = app(CoreReturnWorkflow::class);
        $this->shipments = app(ShipmentService::class);
        $this->documents = app(PrivateDocumentService::class);
    }

    protected function tearDown(): void
    {
        try {
            $this->storageSandbox?->cleanup();
        } finally {
            parent::tearDown();
        }
    }

    public function test_complete_full_reduced_and_no_deposit_paths_preserve_phase_four_invariants(): void
    {
        foreach (['full', 'reduced', 'none'] as $variant) {
            [$order, $business, $thread, $item] = $this->scenario($variant);
            $originalProduct = $order->product_code;
            $depositCharged = $variant !== 'none';
            $this->documents->uploadInvoice($order, $business, new DocumentUploadCommand(
                $this->plOperator, $this->pdf('invoice-'.$variant.'.pdf'), 'accept-invoice-'.$variant, $business->lock_version,
                'FV-'.$variant, '2026-11-18', '2500.75', 'PLN', [
                    'deposit_charged' => $depositCharged,
                    'deposit_amount' => $depositCharged ? '1250.55' : null,
                ],
            ));

            try {
                $this->act($order, $thread, CoreReturnAction::CONFIRM_CORE_RECEIPT_AK, $this->plOperator, 'pl-cannot-receive-'.$variant, $this->inspection());
                self::fail('Poland must not bypass AUTOKLUBY receipt.');
            } catch (AuthorizationException) {
                self::assertSame(CoreReturnState::WAITING_CORE->value, $thread->fresh()->state);
            }

            $this->act($order, $thread, CoreReturnAction::CONFIRM_CORE_RECEIPT_AK, $this->akOperator, 'ak-receive-'.$variant, $this->inspection());
            self::assertSame(CoreReturnState::CORE_RECEIVED_AK->value, $thread->fresh()->state);
            self::assertSame($originalProduct, $order->fresh()->product_code);
            self::assertSame(OrganizationCode::AUTOKLUBY->value, $item->fresh()->current_location);
            $inspection = $thread->coreReturnInspections()->sole();
            try {
                $inspection->update(['note' => 'overwrite']);
                self::fail('Inspection must be immutable.');
            } catch (\LogicException) {
                self::assertSame('Acceptance inspection', $inspection->fresh()->note);
            }

            try {
                $this->act($order, $thread->fresh(), CoreReturnAction::APPROVE_FULL_DEPOSIT_RETURN, $this->plOperator, 'early-decision-'.$variant);
                self::fail('Poland must not bypass shipment receipt and inspection state.');
            } catch (WorkflowConflictException) {
                self::assertSame(CoreReturnState::CORE_RECEIVED_AK->value, $thread->fresh()->state);
            }

            $shipment = $this->shipments->create(new ShipmentCommand($this->akOperator, 'accept-shipment-'.$variant, [
                'direction' => ShipmentDirection::AK_TO_PL->value,
                'carrier' => ShipmentCarrier::GLS->value,
            ]));
            $this->shipments->addItem($shipment, $item, $thread->fresh(), new ShipmentCommand($this->akOperator, 'accept-add-'.$variant, [], $shipment->fresh()->lock_version));
            $this->shipments->send($shipment, new ShipmentCommand($this->akOperator, 'accept-send-'.$variant, ['tracking' => 'ACCEPT-'.$variant], $shipment->fresh()->lock_version));
            self::assertSame(CoreReturnState::SENT_TO_PL->value, $thread->fresh()->state);
            $this->shipments->confirmDelivery($shipment, new ShipmentCommand($this->plOperator, 'accept-deliver-'.$variant, [], $shipment->fresh()->lock_version));
            self::assertSame(CoreReturnState::CORE_INSPECTION->value, $thread->fresh()->state);
            self::assertSame(OrganizationCode::POLAND->value, $item->fresh()->current_location);
            self::assertNull($item->fresh()->active_shipment_id);

            if ($variant === 'none') {
                $this->act($order, $thread->fresh(), CoreReturnAction::CONFIRM_NO_SUPPLIER_DEPOSIT, $this->plOperator, 'accept-none');
                self::assertSame(CoreReturnState::CORE_SETTLED_NO_DEPOSIT->value, $thread->fresh()->state);
                self::assertSame(0, $thread->documents()->count());
            } else {
                $decisionData = $variant === 'reduced'
                    ? ['approved_return_amount' => '1000.10', 'currency' => 'PLN', 'reason' => 'Acceptance reduction']
                    : [];
                $decision = $variant === 'reduced'
                    ? CoreReturnAction::APPROVE_REDUCED_DEPOSIT_RETURN
                    : CoreReturnAction::APPROVE_FULL_DEPOSIT_RETURN;
                $this->act($order, $thread->fresh(), $decision, $this->plOperator, 'accept-decision-'.$variant, $decisionData);
                $approved = $thread->financeEntries()->effective()->where('type', FinanceEntryType::SUPPLIER_DEPOSIT_RETURN_APPROVED)->sole();
                self::assertSame($variant === 'full' ? '1250.55' : '1000.10', $approved->amount);
                if ($variant === 'reduced') {
                    self::assertSame('250.45', $thread->financeEntries()->effective()->where('type', FinanceEntryType::SUPPLIER_DEPOSIT_REDUCTION)->sole()->amount);
                }
                $this->documents->uploadCreditNote($order, $thread->fresh(), new DocumentUploadCommand(
                    $this->plOperator, $this->pdf('credit-'.$variant.'.pdf'), 'accept-credit-'.$variant, $thread->fresh()->lock_version,
                    'DN-'.$variant, '2026-11-18', $approved->amount, 'PLN',
                ));
                self::assertSame(CoreReturnState::CREDIT_ISSUED->value, $thread->fresh()->state);
                $payment = new CoreReturnCommand(CoreReturnAction::CONFIRM_CREDIT_PAID, $this->plOperator, 'accept-paid-'.$variant, [], $thread->fresh()->lock_version);
                $first = $this->core->execute($order, $payment);
                self::assertSame($first->id, $this->core->execute($order, $payment)->id);
                self::assertSame(1, $thread->financeEntries()->where('type', FinanceEntryType::SUPPLIER_CREDIT_PAID)->count());
                self::assertSame(CoreReturnState::CREDIT_PAID->value, $thread->fresh()->state);
            }

            self::assertNotNull($thread->fresh()->completed_at);
            self::assertNull($order->fresh()->closed_at, 'The active business branch must keep the order open.');
            self::assertSame(0, $thread->financeEntries()->where('currency', 'CZK')->count(), 'Customer deposit snapshot must never enter supplier finance.');
            self::assertGreaterThanOrEqual(1, $order->auditLogs()->count());
        }
    }

    private function scenario(string $variant): array
    {
        $order = Order::factory()->stockRebuild()->create([
            'order_no' => 'ACCEPT-4-'.$variant,
            'source_order_id' => 'ACCEPT-4-'.$variant,
            'business_snapshot' => ['customer_deposit' => ['amount' => '999.99', 'currency' => 'CZK']],
        ]);
        $business = ProcessThread::factory()->create([
            'order_id' => $order->id,
            'type' => ProcessThreadType::STOCK_REQUEST,
            'state' => 'WAITING_PL_STOCK',
            'responsible_party' => ResponsibleParty::POLAND,
            'responsible_organization_id' => $this->pl->id,
        ]);
        $core = ProcessThread::factory()->create([
            'order_id' => $order->id,
            'type' => ProcessThreadType::CORE_RETURN,
            'state' => CoreReturnState::WAITING_CORE,
            'responsible_party' => ResponsibleParty::CUSTOMER,
        ]);
        $item = Item::factory()->create([
            'order_id' => $order->id,
            'role' => ItemRole::CORE_RETURN,
            'current_location' => ResponsibleParty::CUSTOMER->value,
        ]);

        return [$order, $business, $core, $item];
    }

    private function act(Order $order, ProcessThread $thread, CoreReturnAction $action, User $actor, string $key, array $data = []): void
    {
        $this->core->execute($order, new CoreReturnCommand($action, $actor, $key, $data, $thread->fresh()->lock_version));
    }

    private function inspection(): array
    {
        return [
            'piece_result' => CoreInspectionPieceResult::DIFFERENT_PIECE->value,
            'transport_condition' => 'NO_VISIBLE_DAMAGE',
            'accident' => false,
            'disassembled' => false,
            'corrosion' => false,
            'axial_rods' => false,
            'note' => 'Acceptance inspection',
        ];
    }

    private function pdf(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF");
    }
}
