<?php

namespace Tests\Feature;

use App\Application\Orders\CoreReturnCommand;
use App\Application\Orders\CoreReturnWorkflow;
use App\Application\Orders\OrderCompletionService;
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
use App\Enums\ShipmentStatus;
use App\Enums\UserRole;
use App\Exceptions\WorkflowConflictException;
use App\Models\FinanceEntry;
use App\Models\Item;
use App\Models\Order;
use App\Models\Organization;
use App\Models\ProcessThread;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CoreReturnWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Organization $ak;

    private Organization $pl;

    private User $akOperator;

    private User $akAdmin;

    private User $plOperator;

    private CoreReturnWorkflow $workflow;

    private ShipmentService $shipments;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ak = Organization::factory()->create(['code' => OrganizationCode::AUTOKLUBY, 'name' => 'AUTOKLUBY']);
        $this->pl = Organization::factory()->create(['code' => OrganizationCode::POLAND, 'name' => 'POLSKO']);
        $this->akOperator = User::factory()->create(['organization_id' => $this->ak->id, 'role' => UserRole::AK_OPERATOR]);
        $this->akAdmin = User::factory()->create(['organization_id' => $this->ak->id, 'role' => UserRole::AK_ADMIN]);
        $this->plOperator = User::factory()->create(['organization_id' => $this->pl->id, 'role' => UserRole::PL_OPERATOR]);
        $this->workflow = app(CoreReturnWorkflow::class);
        $this->shipments = app(ShipmentService::class);
    }

    public function test_ak_receipt_atomically_records_all_inspection_fields_and_does_not_change_product_for_different_piece(): void
    {
        [$order, $thread] = $this->core('inspection');
        $originalCode = $order->product_code;
        $event = $this->act($order, $thread, CoreReturnAction::CONFIRM_CORE_RECEIPT_AK, $this->akOperator, 'inspect', [
            'piece_result' => CoreInspectionPieceResult::DIFFERENT_PIECE->value,
            'transport_condition' => 'PACKAGING_DAMAGED',
            'accident' => true,
            'disassembled' => true,
            'corrosion' => true,
            'axial_rods' => true,
            'note' => 'Jiný kus, předat k posouzení.',
        ]);

        self::assertSame(CoreReturnState::CORE_RECEIVED_AK->value, $thread->fresh()->state);
        self::assertSame($originalCode, $order->fresh()->product_code);
        $inspection = $thread->coreReturnInspections()->sole();
        self::assertSame(CoreInspectionPieceResult::DIFFERENT_PIECE, $inspection->piece_result);
        self::assertTrue($inspection->accident);
        self::assertTrue($inspection->disassembled);
        self::assertTrue($inspection->corrosion);
        self::assertTrue($inspection->axial_rods);
        self::assertSame($this->akOperator->id, $inspection->actor_user_id);
        self::assertNotNull($inspection->inspected_at);
        self::assertSame($inspection->id, $event->metadata['inspection_id']);
        $this->assertDatabaseHas('audit_log', ['subject_id' => $thread->id, 'action' => 'CORE_RETURN_ACTION']);
        try {
            $inspection->update(['note' => 'attempted overwrite']);
            self::fail('Expected append-only inspection protection.');
        } catch (\LogicException) {
            self::assertSame('Jiný kus, předat k posouzení.', $inspection->fresh()->note);
        }
    }

    public function test_inspection_requires_every_flag_and_correct_piece_is_supported(): void
    {
        [$order, $thread] = $this->core('required-flags');
        try {
            $this->act($order, $thread, CoreReturnAction::CONFIRM_CORE_RECEIPT_AK, $this->akOperator, 'missing', [
                'piece_result' => CoreInspectionPieceResult::CORRECT_PIECE->value,
                'transport_condition' => 'NO_VISIBLE_DAMAGE',
            ]);
            self::fail('Expected mandatory inspection validation.');
        } catch (ValidationException $exception) {
            self::assertEqualsCanonicalizing(['accident', 'disassembled', 'corrosion', 'axial_rods'], array_keys($exception->errors()));
            self::assertSame(CoreReturnState::WAITING_CORE->value, $thread->fresh()->state);
            self::assertSame(0, $thread->coreReturnInspections()->count());
        }
    }

    public function test_core_returns_use_existing_ak_to_pl_shipment_engine_for_gls_svoz_and_batches(): void
    {
        foreach ([ShipmentCarrier::GLS, ShipmentCarrier::SVOZ_AUTOKLUBY] as $carrier) {
            $pairs = [$this->receivedCore('ship-'.$carrier->value.'-1'), $this->receivedCore('ship-'.$carrier->value.'-2')];
            $shipment = $this->shipments->create(new ShipmentCommand($this->akOperator, 'create-'.$carrier->value, [
                'direction' => ShipmentDirection::AK_TO_PL->value, 'carrier' => $carrier->value,
            ]));
            foreach ($pairs as $index => [$order, $thread, $item]) {
                $this->shipments->addItem($shipment, $item, $thread, new ShipmentCommand(
                    $this->akOperator, 'add-'.$carrier->value.'-'.$index, [], $shipment->fresh()->lock_version,
                ));
            }
            $sendData = $carrier === ShipmentCarrier::GLS ? ['tracking' => 'GLS-CORE-'.$carrier->value] : [];
            $this->shipments->send($shipment, new ShipmentCommand($this->akOperator, 'send-'.$carrier->value, $sendData, $shipment->fresh()->lock_version));
            self::assertSame(ShipmentStatus::SENT, $shipment->fresh()->status);
            foreach ($pairs as [, $thread, $item]) {
                self::assertSame(CoreReturnState::SENT_TO_PL->value, $thread->fresh()->state);
                self::assertSame($shipment->id, $item->fresh()->active_shipment_id);
            }
            $this->shipments->confirmDelivery($shipment, new ShipmentCommand($this->plOperator, 'deliver-'.$carrier->value, [], $shipment->fresh()->lock_version));
            foreach ($pairs as [, $thread, $item]) {
                self::assertSame(CoreReturnState::CORE_INSPECTION->value, $thread->fresh()->state);
                self::assertSame(OrganizationCode::POLAND->value, $item->fresh()->current_location);
                self::assertNull($item->fresh()->active_shipment_id);
            }
        }
    }

    public function test_core_return_is_never_eligible_for_pl_to_ak(): void
    {
        [, $thread, $item] = $this->receivedCore('wrong-direction');
        $shipment = $this->shipments->create(new ShipmentCommand($this->plOperator, 'create-wrong', [
            'direction' => ShipmentDirection::PL_TO_AK->value, 'carrier' => ShipmentCarrier::DPD->value,
        ]));
        $this->expectException(WorkflowConflictException::class);
        $this->shipments->addItem($shipment, $item, $thread, new ShipmentCommand($this->plOperator, 'add-wrong', [], 0));
    }

    public function test_full_supplier_deposit_return_is_server_derived_and_immutable(): void
    {
        [$order, $thread] = $this->inspectionCore('full');
        $this->recordDeposit($order, $thread, '1250.50', 'PLN');
        $event = $this->act($order, $thread->fresh(), CoreReturnAction::APPROVE_FULL_DEPOSIT_RETURN, $this->plOperator, 'full-approve');
        $entry = FinanceEntry::query()->where('type', FinanceEntryType::SUPPLIER_DEPOSIT_RETURN_APPROVED)->sole();
        self::assertSame('1250.50', $entry->amount);
        self::assertSame('PLN', $entry->currency);
        self::assertSame($event->id, $entry->thread_event_id);
        self::assertNotNull($entry->audit_log_id);
        self::assertSame(CoreReturnState::DEPOSIT_FULL_APPROVED->value, $thread->fresh()->state);
        self::assertNull($thread->fresh()->completed_at);
        self::assertNull($order->fresh()->closed_at);
    }

    public function test_full_return_rejects_client_amount_and_customer_snapshot_as_supplier_deposit(): void
    {
        [$order, $thread] = $this->inspectionCore('snapshot', ['customer_deposit' => ['amount' => '999.00', 'currency' => 'CZK']]);
        try {
            $this->act($order, $thread, CoreReturnAction::RECORD_SUPPLIER_DEPOSIT_ORIGINAL, $this->plOperator, 'snapshot-record', [
                'source' => 'CUSTOMER_DEPOSIT_SNAPSHOT', 'amount' => '999.00', 'currency' => 'CZK', 'reason' => 'copy',
            ]);
            self::fail('Expected customer snapshot rejection.');
        } catch (ValidationException) {
            self::assertSame(0, FinanceEntry::query()->count());
        }
        $this->recordDeposit($order, $thread->fresh(), '800.00', 'PLN');
        $this->expectException(ValidationException::class);
        $this->act($order, $thread->fresh(), CoreReturnAction::APPROVE_FULL_DEPOSIT_RETURN, $this->plOperator, 'full-client', ['amount' => '1.00']);
    }

    public function test_reduced_return_creates_approved_and_derived_reduction_entries(): void
    {
        [$order, $thread] = $this->inspectionCore('reduced');
        $this->recordDeposit($order, $thread, '1000.00', 'EUR');
        $this->act($order, $thread->fresh(), CoreReturnAction::APPROVE_REDUCED_DEPOSIT_RETURN, $this->plOperator, 'reduce', [
            'approved_return_amount' => '725.25', 'currency' => 'EUR', 'reason' => 'Koroze tělesa',
        ]);
        $approved = FinanceEntry::query()->where('type', FinanceEntryType::SUPPLIER_DEPOSIT_RETURN_APPROVED)->sole();
        $reduction = FinanceEntry::query()->where('type', FinanceEntryType::SUPPLIER_DEPOSIT_REDUCTION)->sole();
        self::assertSame('725.25', $approved->amount);
        self::assertSame('274.75', $reduction->amount);
        self::assertSame('Koroze tělesa', $reduction->reason);
        self::assertSame(CoreReturnState::DEPOSIT_REDUCED_APPROVED->value, $thread->fresh()->state);
    }

    public function test_reduced_return_rejects_invalid_amount_reason_or_currency(): void
    {
        foreach (self::invalidReducedAmounts() as $label => [$data]) {
            [$order, $thread] = $this->inspectionCore('invalid-'.md5($label));
            $this->recordDeposit($order, $thread, '1000.00', 'EUR');
            $count = FinanceEntry::query()->count();
            try {
                $this->act($order, $thread->fresh(), CoreReturnAction::APPROVE_REDUCED_DEPOSIT_RETURN, $this->plOperator, 'invalid-reduce-'.$label, $data);
                self::fail('Expected reduced return validation for '.$label);
            } catch (ValidationException) {
                self::assertSame(CoreReturnState::CORE_INSPECTION->value, $thread->fresh()->state);
                self::assertSame($count, FinanceEntry::query()->count());
            }
        }
    }

    public static function invalidReducedAmounts(): array
    {
        return [
            'missing reason' => [['approved_return_amount' => '500.00', 'currency' => 'EUR']],
            'negative' => [['approved_return_amount' => '-1.00', 'currency' => 'EUR', 'reason' => 'x']],
            'too high' => [['approved_return_amount' => '1000.01', 'currency' => 'EUR', 'reason' => 'x']],
            'equal requires full' => [['approved_return_amount' => '1000.00', 'currency' => 'EUR', 'reason' => 'x']],
            'different currency' => [['approved_return_amount' => '500.00', 'currency' => 'PLN', 'reason' => 'x']],
        ];
    }

    public function test_missing_supplier_deposit_is_not_zero_deposit_evidence(): void
    {
        [$order, $thread] = $this->inspectionCore('missing-deposit');
        foreach ([CoreReturnAction::APPROVE_FULL_DEPOSIT_RETURN, CoreReturnAction::CONFIRM_NO_SUPPLIER_DEPOSIT] as $index => $action) {
            try {
                $this->act($order, $thread->fresh(), $action, $this->plOperator, 'missing-'.$index);
                self::fail('Expected explicit supplier finance evidence.');
            } catch (WorkflowConflictException) {
                self::assertSame(CoreReturnState::CORE_INSPECTION->value, $thread->fresh()->state);
            }
        }
    }

    public function test_explicit_no_supplier_deposit_settles_core_and_closes_only_when_all_threads_complete(): void
    {
        [$order, $thread] = $this->inspectionCore('no-deposit');
        $other = ProcessThread::factory()->create([
            'order_id' => $order->id, 'type' => ProcessThreadType::STOCK_REQUEST, 'type_instance' => 1,
            'state' => 'SENT_CUSTOMER', 'responsible_party' => ResponsibleParty::CUSTOMER, 'completed_at' => null,
        ]);
        $this->act($order, $thread, CoreReturnAction::RECORD_NO_SUPPLIER_DEPOSIT, $this->plOperator, 'no-deposit-record', [
            'currency' => 'PLN', 'reason' => 'Na faktuře nebyla záloha účtována.',
        ]);
        $this->act($order, $thread->fresh(), CoreReturnAction::CONFIRM_NO_SUPPLIER_DEPOSIT, $this->plOperator, 'no-deposit-settle');
        self::assertSame(CoreReturnState::CORE_SETTLED_NO_DEPOSIT->value, $thread->fresh()->state);
        self::assertNotNull($thread->fresh()->completed_at);
        self::assertNull($order->fresh()->closed_at);
        self::assertSame(1, FinanceEntry::query()->where('type', FinanceEntryType::SUPPLIER_DEPOSIT_SETTLED_NO_DEPOSIT)->count());
        self::assertSame(0, FinanceEntry::query()->where('type', FinanceEntryType::SUPPLIER_DEPOSIT_RETURN_APPROVED)->count());
        $other->update(['completed_at' => now()]);
        app(OrderCompletionService::class)->refresh($order->fresh());
        self::assertNotNull($order->fresh()->closed_at);
    }

    public function test_permissions_visibility_and_admin_override_reason_are_enforced(): void
    {
        [$order, $thread] = $this->inspectionCore('permissions');
        $this->recordDeposit($order, $thread, '400.00', 'PLN');
        try {
            $this->act($order, $thread->fresh(), CoreReturnAction::APPROVE_FULL_DEPOSIT_RETURN, $this->akOperator, 'operator-denied');
            self::fail('Expected AK operator denial.');
        } catch (AuthorizationException) {
            self::assertSame(CoreReturnState::CORE_INSPECTION->value, $thread->fresh()->state);
        }
        try {
            $this->act($order, $thread->fresh(), CoreReturnAction::APPROVE_FULL_DEPOSIT_RETURN, $this->akAdmin, 'admin-no-reason');
            self::fail('Expected override reason.');
        } catch (ValidationException) {
            self::assertSame(CoreReturnState::CORE_INSPECTION->value, $thread->fresh()->state);
        }
        $this->act($order, $thread->fresh(), CoreReturnAction::APPROVE_FULL_DEPOSIT_RETURN, $this->akAdmin, 'admin-ok', [
            'override_reason' => 'Polská směna nedostupná, schváleno vedoucím.',
        ]);
        $this->assertDatabaseHas('audit_log', ['action' => 'CORE_RETURN_ADMIN_OVERRIDE', 'reason' => 'Polská směna nedostupná, schváleno vedoucím.']);
        self::assertTrue(ProcessThread::query()->visibleTo($this->plOperator)->whereKey($thread->id)->exists());
    }

    public function test_idempotency_payload_conflict_stale_version_and_two_financial_verdicts_are_safe(): void
    {
        [$order, $thread] = $this->inspectionCore('idempotency');
        $this->recordDeposit($order, $thread, '500.00', 'PLN');
        $version = $thread->fresh()->lock_version;
        $command = new CoreReturnCommand(CoreReturnAction::APPROVE_FULL_DEPOSIT_RETURN, $this->plOperator, 'same-verdict', [], $version);
        $first = $this->workflow->execute($order, $command);
        $second = $this->workflow->execute($order, $command);
        self::assertSame($first->id, $second->id);
        self::assertSame(1, FinanceEntry::query()->where('type', FinanceEntryType::SUPPLIER_DEPOSIT_RETURN_APPROVED)->count());
        try {
            $this->workflow->execute($order, new CoreReturnCommand(CoreReturnAction::APPROVE_FULL_DEPOSIT_RETURN, $this->plOperator, 'same-verdict', ['unexpected' => true], $version));
            self::fail('Expected idempotency conflict.');
        } catch (WorkflowConflictException) {
            self::assertSame(1, FinanceEntry::query()->where('type', FinanceEntryType::SUPPLIER_DEPOSIT_RETURN_APPROVED)->count());
        }
        try {
            $this->workflow->execute($order, new CoreReturnCommand(CoreReturnAction::APPROVE_REDUCED_DEPOSIT_RETURN, $this->plOperator, 'concurrent-reduced', [
                'approved_return_amount' => '250.00', 'currency' => 'PLN', 'reason' => 'souběh',
            ], $version));
            self::fail('Expected stale concurrent verdict conflict.');
        } catch (WorkflowConflictException) {
            self::assertSame(CoreReturnState::DEPOSIT_FULL_APPROVED->value, $thread->fresh()->state);
        }
    }

    public function test_transaction_rolls_back_thread_event_finance_and_audit_when_ledger_write_fails(): void
    {
        [$order, $thread] = $this->inspectionCore('rollback');
        $this->recordDeposit($order, $thread, '500.00', 'PLN');
        $beforeEvents = $thread->events()->count();
        $beforeAudits = DB::table('audit_log')->count();
        DB::listen(static function ($query): void {
            if (str_starts_with(strtolower(ltrim($query->sql)), 'insert into "finance_entries"')) {
                throw new \RuntimeException('simulated ledger failure');
            }
        });
        try {
            $this->act($order, $thread->fresh(), CoreReturnAction::APPROVE_FULL_DEPOSIT_RETURN, $this->plOperator, 'rollback-verdict');
            self::fail('Expected simulated failure.');
        } catch (\RuntimeException) {
            self::assertSame(CoreReturnState::CORE_INSPECTION->value, $thread->fresh()->state);
            self::assertSame($beforeEvents, $thread->events()->count());
            self::assertSame($beforeAudits, DB::table('audit_log')->count());
            self::assertSame(0, FinanceEntry::query()->where('type', FinanceEntryType::SUPPLIER_DEPOSIT_RETURN_APPROVED)->count());
        }
    }

    public function test_finance_repairs_append_admin_correction_or_reversal_instead_of_overwriting(): void
    {
        [$order, $thread] = $this->inspectionCore('correction');
        $this->recordDeposit($order, $thread, '500.00', 'PLN');
        $original = FinanceEntry::query()->where('type', FinanceEntryType::SUPPLIER_DEPOSIT_ORIGINAL)->sole();
        $this->act($order, $thread->fresh(), CoreReturnAction::RECORD_FINANCE_CORRECTION, $this->akAdmin, 'correct-ledger', [
            'entry_id' => $original->id, 'amount' => '25.00', 'currency' => 'PLN', 'reason' => 'Oprava částky dle účetní kontroly.',
        ]);
        $this->act($order, $thread->fresh(), CoreReturnAction::REVERSE_FINANCE_ENTRY, $this->akAdmin, 'reverse-ledger', [
            'entry_id' => $original->id, 'currency' => 'PLN', 'reason' => 'Původní zápis byl duplicitní.',
        ]);
        self::assertSame('500.00', $original->fresh()->amount);
        $correction = FinanceEntry::query()->where('type', FinanceEntryType::CORRECTION)->sole();
        $reversal = FinanceEntry::query()->where('type', FinanceEntryType::REVERSAL)->sole();
        self::assertSame($original->id, $correction->corrects_entry_id);
        self::assertSame('-500.00', $reversal->amount);
        self::assertSame($original->id, $reversal->corrects_entry_id);
        try {
            $original->update(['amount' => '1.00']);
            self::fail('Expected append-only finance protection.');
        } catch (\LogicException) {
            self::assertSame('500.00', $original->fresh()->amount);
        }
    }

    private function core(string $suffix, array $businessSnapshot = []): array
    {
        $order = Order::factory()->stockRebuild()->create([
            'order_no' => 'CORE-'.$suffix, 'source_order_id' => 'CORE-'.$suffix,
            'business_snapshot' => $businessSnapshot,
        ]);
        $thread = ProcessThread::factory()->create([
            'order_id' => $order->id, 'type' => ProcessThreadType::CORE_RETURN,
            'state' => CoreReturnState::WAITING_CORE->value, 'responsible_party' => ResponsibleParty::CUSTOMER,
        ]);
        Item::factory()->create([
            'order_id' => $order->id, 'role' => ItemRole::CORE_RETURN,
            'current_location' => ResponsibleParty::CUSTOMER->value,
        ]);

        return [$order, $thread, $order->items()->sole()];
    }

    private function receivedCore(string $suffix): array
    {
        [$order, $thread, $item] = $this->core($suffix);
        $this->act($order, $thread, CoreReturnAction::CONFIRM_CORE_RECEIPT_AK, $this->akOperator, 'receipt-'.$suffix, $this->inspectionData());

        return [$order, $thread->fresh(), $item->fresh()];
    }

    private function inspectionCore(string $suffix, array $snapshot = []): array
    {
        [$order, $thread] = $this->core($suffix, $snapshot);
        $thread->update([
            'state' => CoreReturnState::CORE_INSPECTION->value,
            'responsible_party' => ResponsibleParty::POLAND,
            'responsible_organization_id' => $this->pl->id,
        ]);

        return [$order, $thread->fresh()];
    }

    private function inspectionData(): array
    {
        return [
            'piece_result' => CoreInspectionPieceResult::CORRECT_PIECE->value,
            'transport_condition' => 'NO_VISIBLE_DAMAGE', 'accident' => false, 'disassembled' => false,
            'corrosion' => false, 'axial_rods' => false,
        ];
    }

    private function recordDeposit(Order $order, ProcessThread $thread, string $amount, string $currency): void
    {
        $this->act($order, $thread, CoreReturnAction::RECORD_SUPPLIER_DEPOSIT_ORIGINAL, $this->plOperator, 'deposit-'.$order->id, [
            'amount' => $amount, 'currency' => $currency, 'reason' => 'Polská dodavatelská faktura',
        ]);
    }

    private function act(Order $order, ProcessThread $thread, CoreReturnAction $action, User $actor, string $key, array $data = [])
    {
        return $this->workflow->execute($order, new CoreReturnCommand($action, $actor, $key, $data, $thread->fresh()->lock_version));
    }
}
