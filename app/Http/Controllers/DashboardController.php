<?php

namespace App\Http\Controllers;

use App\Application\Orders\CustomRebuildPresentation;
use App\Application\Orders\StockRebuildPresentation;
use App\Enums\BuybackState;
use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Enums\OrderType;
use App\Enums\OrganizationCode;
use App\Enums\ProcessThreadType;
use App\Enums\WarrantyState;
use App\Models\IntegrationEvent;
use App\Models\Order;
use App\Models\ProcessThread;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(
        Request $request,
        CustomRebuildPresentation $customPresentation,
        StockRebuildPresentation $stockPresentation,
    ): View {
        $user = $request->user();
        $this->authorize('viewAny', Order::class);
        $isPoland = $user->organization->code === OrganizationCode::POLAND;
        $availableQueues = $isPoland
            ? ['pl_new', 'pl_securing', 'pl_available', 'pl_sent', 'core_receive', 'core_decision', 'credit_upload', 'credit_payment', 'completed']
            : ['pending_stock', 'customer_fulfillment', 'stock_replenishment', 'core_return', 'waiting_ak', 'waiting_poland', 'completed'];
        $activeQueue = in_array($request->query('queue'), $availableQueues, true)
            ? (string) $request->query('queue')
            : null;

        $visibleOrders = Order::query()->visibleTo($user);
        $orders = (clone $visibleOrders)
            ->with(['processThreads' => fn ($threads) => $threads
                ->visibleTo($user)
                ->where('type', '!=', ProcessThreadType::WARRANTY)
                ->with(['responsibleOrganization', 'events'])])
            ->latest()
            ->limit(50)
            ->get();

        $rows = $orders->flatMap(function (Order $order) use ($user, $customPresentation, $stockPresentation): array {
            if ($order->order_type === OrderType::CUSTOM_REBUILD) {
                if (! $order->processThreads->contains('type', ProcessThreadType::CUSTOM_REBUILD)) {
                    return [];
                }

                return [[
                    'kind' => 'custom',
                    'order' => $order,
                    'workflow' => $customPresentation->for($order, $user),
                    'queues' => [],
                ]];
            }

            if ($order->order_type !== OrderType::STOCK_REBUILD) {
                return [];
            }

            return $order->processThreads->map(fn (ProcessThread $thread): array => [
                'kind' => 'stock',
                'order' => $order,
                'workflow' => $stockPresentation->branch($order, $thread, $user),
                'queues' => $stockPresentation->queueCodes($thread, $user),
            ])->all();
        });
        $queueCounts = collect($availableQueues)->mapWithKeys(
            fn (string $queue): array => [$queue => $rows->filter(fn (array $row): bool => in_array($queue, $row['queues'], true))->count()],
        );
        if ($activeQueue !== null) {
            $rows = $rows->filter(fn (array $row): bool => in_array($activeQueue, $row['queues'], true))->values();
        }

        $visibleThreadQuery = ProcessThread::query()->visibleTo($user)->where('type', '!=', ProcessThreadType::WARRANTY);
        $coreQuery = ProcessThread::query()->visibleTo($user)->where('type', ProcessThreadType::CORE_RETURN);
        $invoiceThreads = ProcessThread::query()->visibleTo($user)
            ->whereIn('type', [ProcessThreadType::CUSTOM_REBUILD, ProcessThreadType::STOCK_REQUEST, ProcessThreadType::STOCK_REPLENISHMENT]);

        $warrantyKpis = ProcessThread::query()
            ->visibleTo($user)
            ->where('type', ProcessThreadType::WARRANTY)
            ->selectRaw('COUNT(CASE WHEN completed_at IS NULL THEN 1 END) AS active_count')
            ->selectRaw("COUNT(CASE WHEN completed_at IS NULL AND responsible_party = 'AUTOKLUBY' THEN 1 END) AS autokluby_count")
            ->selectRaw("COUNT(CASE WHEN completed_at IS NULL AND responsible_party = 'POLAND' THEN 1 END) AS poland_count")
            ->selectRaw('COUNT(CASE WHEN state IN (?, ?) THEN 1 END) AS transport_count', [WarrantyState::SENT_TO_PL->value, WarrantyState::SENT_TO_AK->value])
            ->first();
        $buybackKpis = ProcessThread::query()->visibleTo($user)->where('type', ProcessThreadType::BUYOUT)
            ->selectRaw('COUNT(CASE WHEN completed_at IS NULL THEN 1 END) AS active_count')
            ->selectRaw('COUNT(CASE WHEN state IN (?, ?) THEN 1 END) AS poland_count', [BuybackState::WAITING_FOR_PL_OFFER->value, BuybackState::PL_OFFERED->value])
            ->selectRaw('COUNT(CASE WHEN state = ? THEN 1 END) AS final_price_count', [BuybackState::WAITING_FOR_FINAL_PRICE->value])
            ->selectRaw('COUNT(CASE WHEN state IN (?, ?, ?) THEN 1 END) AS customer_count', [BuybackState::FINAL_PRICE_APPROVED->value, BuybackState::CUSTOMER_OFFER_READY->value, BuybackState::OFFER_SENT->value])
            ->selectRaw('COUNT(CASE WHEN state IN (?, ?, ?) THEN 1 END) AS item_count', [BuybackState::CUSTOMER_ACCEPTED->value, BuybackState::ITEM_RECEIVED_AK->value, BuybackState::ITEM_CHECKED_AK->value])
            ->selectRaw('COUNT(CASE WHEN state IN (?, ?) THEN 1 END) AS finance_count', [BuybackState::INVOICE_TO_PL_REQUIRED->value, BuybackState::WAITING_FOR_PL_PAYMENT->value])
            ->first();

        return view('dashboard', [
            'rows' => $rows,
            'totalOrders' => (clone $visibleOrders)->count(),
            'waitingForUs' => (clone $visibleThreadQuery)
                ->whereNull('completed_at')
                ->where('responsible_organization_id', $user->organization_id)
                ->count(),
            'waitingForPoland' => (clone $visibleThreadQuery)
                ->whereNull('completed_at')
                ->where('responsible_party', 'POLAND')
                ->count(),
            'integrationErrors' => $isPoland ? 0 : IntegrationEvent::query()->where('status', 'FAILED')->count(),
            'isPoland' => $isPoland,
            'availableQueues' => $availableQueues,
            'activeQueue' => $activeQueue,
            'queueCounts' => $queueCounts,
            'returnKpis' => [
                'waiting_receipt' => (clone $coreQuery)->where('state', 'WAITING_CORE')->count(),
                'waiting_poland_core' => (clone $coreQuery)->whereIn('state', ['SENT_TO_PL', 'CORE_INSPECTION'])->count(),
                'missing_invoices' => (clone $invoiceThreads)->whereDoesntHave('documents', fn ($documents) => $documents->where('type', DocumentType::SUPPLIER_INVOICE)->where('status', DocumentStatus::ACTIVE))->count(),
                'waiting_deposit_decision' => (clone $coreQuery)->where('state', 'CORE_INSPECTION')->count(),
                'waiting_credit_notes' => (clone $coreQuery)->whereIn('state', ['DEPOSIT_FULL_APPROVED', 'DEPOSIT_REDUCED_APPROVED'])->count(),
                'waiting_credit_payment' => (clone $coreQuery)->where('state', 'CREDIT_ISSUED')->count(),
                'reduced_deposits' => (clone $coreQuery)->where('state', 'DEPOSIT_REDUCED_APPROVED')->count(),
            ],
            'warrantyKpis' => [
                'active' => (int) $warrantyKpis->active_count,
                'autokluby' => (int) $warrantyKpis->autokluby_count,
                'poland' => (int) $warrantyKpis->poland_count,
                'transport' => (int) $warrantyKpis->transport_count,
            ],
            'buybackKpis' => [
                'active' => (int) $buybackKpis->active_count,
                'poland' => (int) $buybackKpis->poland_count,
                'final_price' => (int) $buybackKpis->final_price_count,
                'customer' => (int) $buybackKpis->customer_count,
                'item' => (int) $buybackKpis->item_count,
                'finance' => (int) $buybackKpis->finance_count,
            ],
        ]);
    }
}
