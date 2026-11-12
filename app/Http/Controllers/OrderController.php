<?php

namespace App\Http\Controllers;

use App\Application\Orders\CustomRebuildPresentation;
use App\Application\Orders\CustomRebuildTimeline;
use App\Application\Orders\StockRebuildPresentation;
use App\Application\Orders\StockRebuildTimeline;
use App\Enums\CustomRebuildAction;
use App\Enums\CustomRebuildState;
use App\Enums\ItemRole;
use App\Enums\OrderType;
use App\Enums\OrganizationCode;
use App\Enums\ProcessThreadType;
use App\Enums\StockRebuildAction;
use App\Enums\UserRole;
use App\Models\Order;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class OrderController extends Controller
{
    public function show(
        Request $request,
        Order $order,
        CustomRebuildPresentation $presentation,
        CustomRebuildTimeline $timeline,
        StockRebuildPresentation $stockPresentation,
        StockRebuildTimeline $stockTimeline,
    ): View {
        $this->authorize('view', $order);

        if ($order->order_type === OrderType::STOCK_REBUILD) {
            $isAutokluby = $request->user()->organization->code === OrganizationCode::AUTOKLUBY;
            $user = $request->user();
            $order->load([
                'processThreads' => fn ($threads) => $threads
                    ->visibleTo($user)
                    ->with(['responsibleOrganization', 'events.actorUser', 'events.actorOrganization']),
                'items' => function ($items) use ($order, $isAutokluby, $user): void {
                    if ($isAutokluby) {
                        return;
                    }

                    $roles = [];
                    if ($order->processThreads()->visibleTo($user)->where('type', ProcessThreadType::STOCK_REQUEST)->exists()) {
                        $roles[] = ItemRole::STOCK_ITEM;
                    }
                    if ($order->processThreads()->visibleTo($user)->where('type', ProcessThreadType::STOCK_REPLENISHMENT)->exists()) {
                        $roles[] = ItemRole::STOCK_REPLACEMENT;
                    }
                    $items->whereIn('role', $roles);
                },
            ]);
            if ($isAutokluby) {
                $order->load('customer');
            }

            $branches = $order->processThreads->mapWithKeys(fn ($thread): array => [
                $thread->id => $stockPresentation->branch($order, $thread, $user),
            ]);
            $actions = $branches->flatMap(fn (array $branch): array => $branch['allowed_actions'])->unique(
                fn (StockRebuildAction $action): string => $action->value,
            );

            return view('orders.stock-show', [
                'order' => $order,
                'branches' => $branches,
                'timeline' => $stockTimeline->for($order->processThreads, $user),
                'stockInformation' => $stockPresentation->stockInformation($order),
                'actionFormKeys' => $actions->mapWithKeys(
                    fn (StockRebuildAction $action): array => [$action->value => (string) Str::uuid()],
                )->all(),
                'qrMode' => false,
                'selectedItem' => null,
            ]);
        }

        if ($order->order_type !== OrderType::CUSTOM_REBUILD) {
            $order->load(['processThreads.responsibleOrganization', 'items']);
            if ($request->user()->organization->code === OrganizationCode::AUTOKLUBY) {
                $order->load('customer');
            }

            return view('orders.show-readonly', compact('order'));
        }

        $order->load([
            'items',
            'processThreads' => fn ($threads) => $threads
                ->where('type', ProcessThreadType::CUSTOM_REBUILD)
                ->with(['responsibleOrganization', 'events.actorUser', 'events.actorOrganization']),
        ]);

        if ($request->user()->organization->code === OrganizationCode::AUTOKLUBY) {
            $order->load('customer');
        }

        $workflow = $presentation->for($order, $request->user());

        return view('orders.show', [
            'order' => $order,
            'workflow' => $workflow,
            'timeline' => $timeline->for($workflow['thread'], $request->user()),
            'actionFormKeys' => $this->actionFormKeys($workflow['allowed_actions'], $request->user()->role === UserRole::AK_ADMIN),
            'overrideStates' => CustomRebuildState::cases(),
            'qrMode' => false,
            'selectedItem' => null,
        ]);
    }

    /** @param array<int, CustomRebuildAction> $actions */
    private function actionFormKeys(array $actions, bool $includeOverride): array
    {
        if ($includeOverride) {
            $actions[] = CustomRebuildAction::ADMIN_OVERRIDE;
        }

        return collect($actions)->mapWithKeys(
            fn (CustomRebuildAction $action): array => [$action->value => (string) Str::uuid()],
        )->all();
    }
}
