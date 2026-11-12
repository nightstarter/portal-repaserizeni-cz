<?php

namespace App\Http\Controllers;

use App\Application\Orders\CustomRebuildPresentation;
use App\Application\Orders\StockRebuildPresentation;
use App\Enums\OrderType;
use App\Enums\OrganizationCode;
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
            ? ['pl_new', 'pl_securing', 'pl_available', 'pl_sent', 'completed']
            : ['pending_stock', 'customer_fulfillment', 'stock_replenishment', 'core_return', 'waiting_ak', 'waiting_poland', 'completed'];
        $activeQueue = in_array($request->query('queue'), $availableQueues, true)
            ? (string) $request->query('queue')
            : null;

        $visibleOrders = Order::query()->visibleTo($user);
        $orders = (clone $visibleOrders)
            ->with(['processThreads' => fn ($threads) => $threads
                ->visibleTo($user)
                ->with(['responsibleOrganization', 'events'])])
            ->latest()
            ->limit(50)
            ->get();

        $rows = $orders->flatMap(function (Order $order) use ($user, $customPresentation, $stockPresentation): array {
            if ($order->order_type === OrderType::CUSTOM_REBUILD) {
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

        $visibleThreadQuery = ProcessThread::query()->visibleTo($user);

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
        ]);
    }
}
