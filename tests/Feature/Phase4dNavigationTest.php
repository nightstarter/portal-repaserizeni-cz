<?php

namespace Tests\Feature;

use App\Enums\FinanceEntryStatus;
use App\Enums\FinanceEntryType;
use App\Enums\ItemRole;
use App\Enums\OrganizationCode;
use App\Enums\ProcessThreadType;
use App\Enums\ResponsibleParty;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\FinanceEntry;
use App\Models\Item;
use App\Models\Order;
use App\Models\OrderCustomer;
use App\Models\Organization;
use App\Models\ProcessThread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class Phase4dNavigationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $autokluby;

    private Organization $poland;

    private User $akOperator;

    private User $plOperator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->autokluby = Organization::factory()->create(['code' => OrganizationCode::AUTOKLUBY, 'name' => 'AUTOKLUBY']);
        $this->poland = Organization::factory()->create(['code' => OrganizationCode::POLAND, 'name' => 'POLSKO']);
        $this->akOperator = User::factory()->create(['organization_id' => $this->autokluby->id, 'role' => UserRole::AK_OPERATOR]);
        $this->plOperator = User::factory()->create(['organization_id' => $this->poland->id, 'role' => UserRole::PL_OPERATOR, 'preferred_locale' => 'pl']);
    }

    public function test_menu_routes_are_visible_active_authorized_and_empty_queues_are_clear(): void
    {
        $this->get(route('returns.index'))->assertRedirect(route('login'));
        $this->get(route('finance.index'))->assertRedirect(route('login'));

        $returns = $this->actingAs($this->akOperator)->get('/returns')->assertOk()
            ->assertSee('Vratky')
            ->assertSee('Doklady a finance')
            ->assertSee('Žádné vratky v této frontě.');
        $returns->assertSee('<a class="active" href="'.route('returns.index').'"', false)
            ->assertSee('aria-current="page"', false);

        $finance = $this->actingAs($this->akOperator)->get('/finance')->assertOk()
            ->assertSee('Chybějící dodavatelská faktura')
            ->assertSee('Čekající rozhodnutí o záloze')
            ->assertSee('Chybějící dobropis')
            ->assertSee('Čekající platba')
            ->assertSee('Krácené vypořádání')
            ->assertSee('V této frontě nejsou žádné položky.');
        $finance->assertSee('<a class="active" href="'.route('finance.index').'"', false)
            ->assertSee('aria-current="page"', false);
    }

    public function test_poland_direct_pages_are_row_scoped_and_never_expose_customer_pii_or_deposit(): void
    {
        [$visibleOrder, $visibleCore] = $this->coreOrder('VISIBLE-RETURN-PL', 'CORE_INSPECTION', $this->poland);
        [$hiddenOrder] = $this->coreOrder('HIDDEN-RETURN-AK', 'CORE_INSPECTION', $this->autokluby);
        OrderCustomer::factory()->for($visibleOrder)->create([
            'name' => 'Tajný zákazník 4D',
            'email' => 'phase4d-private@example.test',
            'phone' => '+420999888777',
        ]);
        $visibleOrder->update(['business_snapshot' => ['customer_deposit' => ['amount' => '98765.43', 'currency' => 'CZK']]]);
        ProcessThread::factory()->create([
            'order_id' => $visibleOrder->id,
            'type' => ProcessThreadType::STOCK_REQUEST,
            'state' => 'WAITING_PL_STOCK',
            'responsible_party' => ResponsibleParty::POLAND,
            'responsible_organization_id' => $this->poland->id,
        ]);

        foreach (['returns.index', 'finance.index'] as $route) {
            $this->actingAs($this->plOperator)->get(route($route))->assertOk()
                ->assertSee('VISIBLE-RETURN-PL')
                ->assertDontSee($hiddenOrder->order_no)
                ->assertDontSee('Tajný zákazník 4D')
                ->assertDontSee('phase4d-private@example.test')
                ->assertDontSee('+420999888777')
                ->assertDontSee('98765.43')
                ->assertDontSee('CZK');
        }

        $this->actingAs($this->plOperator)->get(route('orders.show', $hiddenOrder))->assertNotFound();
        self::assertSame($this->poland->id, $visibleCore->responsible_organization_id);
    }

    public function test_return_filters_show_operational_queues_and_supplier_deposit_status(): void
    {
        [$akOrder] = $this->coreOrder('RETURN-WAIT-AK', 'WAITING_CORE', $this->autokluby);
        [$plOrder] = $this->coreOrder('RETURN-WAIT-PL', 'SENT_TO_PL', $this->poland);
        [$creditOrder, $creditThread] = $this->coreOrder('RETURN-CREDIT', 'DEPOSIT_REDUCED_APPROVED', $this->poland);
        [$paymentOrder] = $this->coreOrder('RETURN-PAYMENT', 'CREDIT_ISSUED', $this->poland);
        $this->finance($creditOrder, $creditThread, FinanceEntryType::SUPPLIER_DEPOSIT_ORIGINAL, '500.00');
        $this->finance($creditOrder, $creditThread, FinanceEntryType::SUPPLIER_DEPOSIT_REDUCTION, '125.00');

        $this->actingAs($this->akOperator)->get(route('returns.index', ['filter' => 'autokluby']))->assertOk()
            ->assertSee($akOrder->order_no)->assertDontSee($plOrder->order_no)->assertDontSee($creditOrder->order_no);
        $this->actingAs($this->akOperator)->get(route('returns.index', ['filter' => 'poland']))->assertOk()
            ->assertSee($plOrder->order_no)->assertDontSee($akOrder->order_no);
        $this->actingAs($this->akOperator)->get(route('returns.index', ['filter' => 'credit_note']))->assertOk()
            ->assertSee($creditOrder->order_no)->assertSee('Krácené vypořádání')->assertDontSee($paymentOrder->order_no);
        $this->actingAs($this->akOperator)->get(route('returns.index', ['filter' => 'payment']))->assertOk()
            ->assertSee($paymentOrder->order_no)->assertDontSee($creditOrder->order_no);
    }

    public function test_finance_queues_and_order_section_navigation_link_to_authorized_details(): void
    {
        $businessOrder = Order::factory()->stockRebuild()->create(['order_no' => 'FIN-MISSING-INVOICE', 'product_code' => 'FIN-INV']);
        ProcessThread::factory()->create([
            'order_id' => $businessOrder->id,
            'type' => ProcessThreadType::STOCK_REQUEST,
            'state' => 'WAITING_PL_STOCK',
            'responsible_party' => ResponsibleParty::POLAND,
            'responsible_organization_id' => $this->poland->id,
        ]);
        [$decisionOrder] = $this->coreOrder('FIN-DECISION', 'CORE_INSPECTION', $this->poland);
        [$creditOrder, $creditThread] = $this->coreOrder('FIN-CREDIT', 'DEPOSIT_REDUCED_APPROVED', $this->poland);
        [$paymentOrder] = $this->coreOrder('FIN-PAYMENT', 'CREDIT_ISSUED', $this->poland);
        $this->finance($creditOrder, $creditThread, FinanceEntryType::SUPPLIER_DEPOSIT_ORIGINAL, '700.00');
        $this->finance($creditOrder, $creditThread, FinanceEntryType::SUPPLIER_DEPOSIT_REDUCTION, '200.00');

        $this->actingAs($this->plOperator)->get(route('finance.index'))->assertOk()
            ->assertSee($businessOrder->order_no)
            ->assertSee($decisionOrder->order_no)
            ->assertSee($creditOrder->order_no)
            ->assertSee($paymentOrder->order_no)
            ->assertSee(route('orders.show', $creditOrder).'#finance', false);

        $this->actingAs($this->plOperator)->get(route('orders.show', $creditOrder))->assertOk()
            ->assertSee('Przegląd')
            ->assertSee('Zwrot')
            ->assertSee('Dokumenty i finanse')
            ->assertSee('href="#finance"', false);
    }

    private function coreOrder(string $number, string $state, Organization $responsible): array
    {
        $order = Order::factory()->stockRebuild()->create(['order_no' => $number, 'product_code' => 'PRODUCT-'.$number]);
        $thread = ProcessThread::factory()->create([
            'order_id' => $order->id,
            'type' => ProcessThreadType::CORE_RETURN,
            'state' => $state,
            'responsible_party' => $responsible->code === OrganizationCode::POLAND ? ResponsibleParty::POLAND : ResponsibleParty::AUTOKLUBY,
            'responsible_organization_id' => $responsible->id,
        ]);
        Item::factory()->create([
            'order_id' => $order->id,
            'role' => ItemRole::CORE_RETURN,
            'current_location' => $responsible->code->value,
        ]);

        return [$order, $thread];
    }

    private function finance(Order $order, ProcessThread $thread, FinanceEntryType $type, string $amount): void
    {
        $audit = AuditLog::query()->create([
            'actor_user_id' => $this->plOperator->id,
            'organization_id' => $this->poland->id,
            'order_id' => $order->id,
            'subject_type' => ProcessThread::class,
            'subject_id' => $thread->id,
            'action' => 'PHASE_4D_TEST_FINANCE',
        ]);
        FinanceEntry::query()->create([
            'order_id' => $order->id,
            'process_thread_id' => $thread->id,
            'audit_log_id' => $audit->id,
            'type' => $type,
            'amount' => $amount,
            'currency' => 'PLN',
            'status' => FinanceEntryStatus::CONFIRMED,
            'actor_user_id' => $this->plOperator->id,
            'actor_organization_id' => $this->poland->id,
            'idempotency_key' => (string) Str::uuid(),
            'request_hash' => hash('sha256', (string) Str::uuid()),
        ]);
    }
}
