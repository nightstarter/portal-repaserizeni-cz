<?php

namespace App\Http\Controllers;

use App\Application\Messages\DiscussionService;
use App\Application\Orders\CoreReturnPresentation;
use App\Application\Orders\CustomRebuildPresentation;
use App\Application\Orders\CustomRebuildTimeline;
use App\Application\Orders\StockRebuildPresentation;
use App\Application\Orders\StockRebuildTimeline;
use App\Application\Orders\WarrantyPresentation;
use App\Enums\BuybackPriceType;
use App\Enums\BuybackState;
use App\Enums\CoreReturnAction;
use App\Enums\CustomRebuildAction;
use App\Enums\CustomRebuildState;
use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Enums\ItemRole;
use App\Enums\OrderType;
use App\Enums\OrganizationCode;
use App\Enums\ProcessThreadType;
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
        CoreReturnPresentation $corePresentation,
        DiscussionService $discussion,
        WarrantyPresentation $warrantyPresentation,
    ): View {
        $this->authorize('view', $order);
        $discussionUnreadCount = $discussion->unreadCount($order, $request->user());
        $warrantiesByItem = $warrantyPresentation->forOrder($order, $request->user());
        $warrantyCreateKeys = $order->items()->pluck('id')->mapWithKeys(
            fn (int $id): array => [$id => (string) Str::uuid()],
        )->all();
        if ($request->user()->organization->code === OrganizationCode::POLAND
            && $warrantiesByItem->isNotEmpty()
            && ! $order->processThreads()->visibleTo($request->user())->where('type', '!=', ProcessThreadType::WARRANTY)->exists()) {
            $visibleItemIds = $warrantiesByItem->keys();
            $order->load(['items' => fn ($items) => $items->whereIn('id', $visibleItemIds)]);

            return view('orders.warranty-show', compact('order', 'discussionUnreadCount', 'warrantiesByItem', 'warrantyCreateKeys'));
        }

        if ($request->user()->organization->code === OrganizationCode::POLAND
            && $order->order_type === OrderType::BUYOUT) {
            $buyback = $order->processThreads()->visibleTo($request->user())
                ->where('type', ProcessThreadType::BUYOUT)
                ->with([
                    'item',
                    'buybackPriceQuotes' => fn ($quotes) => $quotes->where('type', BuybackPriceType::POLAND_OFFER)->orderBy('version'),
                    'documents' => fn ($documents) => $documents
                        ->where('type', DocumentType::BUYBACK_INVOICE_TO_POLAND)
                        ->where('status', DocumentStatus::ACTIVE),
                ])->firstOrFail();
            $actualState = BuybackState::from($buyback->state);
            $state = in_array($actualState, [
                BuybackState::WAITING_FOR_PL_OFFER, BuybackState::PL_OFFERED,
                BuybackState::READY_TO_PL, BuybackState::SENT_TO_PL, BuybackState::RECEIVED_PL,
                BuybackState::INVOICE_TO_PL_REQUIRED, BuybackState::WAITING_FOR_PL_PAYMENT,
                BuybackState::PAID_BY_PL,
            ], true) ? $actualState->value : 'BUYBACK_WAITING_AK_DECISION';

            return view('orders.buyback-show', compact('order', 'buyback', 'state', 'discussionUnreadCount'));
        }

        if ($order->order_type === OrderType::STOCK_REBUILD) {
            $isAutokluby = $request->user()->organization->code === OrganizationCode::AUTOKLUBY;
            $user = $request->user();
            $order->load([
                'processThreads' => fn ($threads) => $threads
                    ->visibleTo($user)
                    ->where('type', '!=', ProcessThreadType::WARRANTY)
                    ->with(['responsibleOrganization', 'events.actorUser', 'events.actorOrganization', 'coreReturnInspections', 'financeEntries.actorUser', 'documents.uploader']),
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
                $thread->id => $thread->type === ProcessThreadType::CORE_RETURN
                    ? $corePresentation->for($order, $thread, $user)
                    : $stockPresentation->branch($order, $thread, $user),
            ]);
            $actions = $branches->flatMap(fn (array $branch): array => $branch['allowed_actions'])->unique(
                fn ($action): string => $action->value,
            );

            return view('orders.stock-show', [
                'order' => $order,
                'branches' => $branches,
                'timeline' => $stockTimeline->for($order->processThreads, $user),
                'stockInformation' => $stockPresentation->stockInformation($order),
                'actionFormKeys' => $actions->mapWithKeys(
                    fn ($action): array => [$action->value => (string) Str::uuid()],
                )->all(),
                'qrMode' => false,
                'selectedItem' => null,
                'coreActionFormKeys' => collect(CoreReturnAction::cases())->mapWithKeys(fn ($action) => [$action->value => (string) Str::uuid()])->all(),
                'documentFormKeys' => $order->processThreads->mapWithKeys(fn ($thread) => [$thread->id => (string) Str::uuid()])->all(),
                'discussionUnreadCount' => $discussionUnreadCount,
                'warrantiesByItem' => $warrantiesByItem,
                'warrantyCreateKeys' => $warrantyCreateKeys,
            ]);
        }

        if ($order->order_type !== OrderType::CUSTOM_REBUILD) {
            $order->load(['processThreads' => fn ($threads) => $threads->where('type', '!=', ProcessThreadType::WARRANTY)->with('responsibleOrganization'), 'items']);
            if ($request->user()->organization->code === OrganizationCode::AUTOKLUBY) {
                $order->load('customer');
            }

            return view('orders.show-readonly', compact('order', 'discussionUnreadCount', 'warrantiesByItem', 'warrantyCreateKeys'));
        }

        $order->load([
            'items',
            'documents.uploader',
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
            'documentFormKeys' => $order->processThreads->mapWithKeys(fn ($thread) => [$thread->id => (string) Str::uuid()])->all(),
            'discussionUnreadCount' => $discussionUnreadCount,
            'warrantiesByItem' => $warrantiesByItem,
            'warrantyCreateKeys' => $warrantyCreateKeys,
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
