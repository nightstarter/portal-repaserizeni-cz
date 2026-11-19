<?php

namespace App\Http\Controllers;

use App\Application\Orders\CustomRebuildPresentation;
use App\Application\Orders\CustomRebuildTimeline;
use App\Application\Orders\StockRebuildPresentation;
use App\Application\Orders\StockRebuildTimeline;
use App\Application\Orders\WarrantyPresentation;
use App\Enums\CustomRebuildAction;
use App\Enums\CustomRebuildState;
use App\Enums\OrderType;
use App\Enums\OrganizationCode;
use App\Enums\ProcessThreadType;
use App\Enums\StockRebuildAction;
use App\Enums\UserRole;
use App\Models\Item;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class QrItemController extends Controller
{
    public function __invoke(
        Request $request,
        string $randomToken,
        CustomRebuildPresentation $presentation,
        CustomRebuildTimeline $timeline,
        StockRebuildPresentation $stockPresentation,
        StockRebuildTimeline $stockTimeline,
        WarrantyPresentation $warrantyPresentation,
    ): View {
        $item = Item::query()->where('qr_token', $randomToken)->firstOrFail();
        $order = $item->order;

        abort_unless($request->user()->can('view', $order), 404);

        $warrantyQuery = $item->warrantyThreads()->whereNull('completed_at');
        if ($request->user()->organization->code === OrganizationCode::POLAND) {
            $warrantyQuery->visibleTo($request->user());
        }
        $warrantyThread = $warrantyQuery
            ->with([
                'item', 'responsibleOrganization', 'latestEvent',
                'events.actorUser', 'events.actorOrganization', 'shipmentItems.shipment',
            ])
            ->first();
        if ($warrantyThread !== null) {
            return view('items.warranty-qr', [
                'order' => $order,
                'selectedItem' => $item,
                'warranty' => $warrantyPresentation->for($warrantyThread, $request->user()),
                'qrMode' => true,
            ]);
        }

        if ($order->order_type === OrderType::STOCK_REBUILD) {
            $threadType = $stockPresentation->threadTypeForItem($item);
            abort_if($threadType === null, 404);
            $threadQuery = $order->processThreads()->where('type', $threadType);
            if ($request->user()->organization->code === OrganizationCode::POLAND) {
                $threadQuery->visibleTo($request->user());
            }
            $thread = $threadQuery
                ->with(['responsibleOrganization', 'events.actorUser', 'events.actorOrganization'])
                ->firstOrFail();
            $workflow = $stockPresentation->branch($order, $thread, $request->user());

            return view('items.stock-qr', [
                'order' => $order,
                'selectedItem' => $item,
                'workflow' => $workflow,
                'timeline' => $stockTimeline->for(new Collection([$thread]), $request->user()),
                'stockInformation' => $stockPresentation->stockInformation($order),
                'actionFormKeys' => collect($workflow['allowed_actions'])->mapWithKeys(
                    fn (StockRebuildAction $action): array => [$action->value => (string) Str::uuid()],
                )->all(),
                'qrMode' => true,
            ]);
        }

        $order->load([
            'items',
            'processThreads' => fn ($threads) => $threads
                ->where('type', ProcessThreadType::CUSTOM_REBUILD)
                ->with(['responsibleOrganization', 'events.actorUser', 'events.actorOrganization']),
        ]);
        $workflow = $presentation->for($order, $request->user());
        $actions = $workflow['allowed_actions'];
        if ($request->user()->role === UserRole::AK_ADMIN) {
            $actions[] = CustomRebuildAction::ADMIN_OVERRIDE;
        }

        return view('items.qr', [
            'order' => $order,
            'selectedItem' => $item,
            'workflow' => $workflow,
            'timeline' => $timeline->for($workflow['thread'], $request->user()),
            'actionFormKeys' => collect($actions)->mapWithKeys(
                fn (CustomRebuildAction $action): array => [$action->value => (string) Str::uuid()],
            )->all(),
            'overrideStates' => CustomRebuildState::cases(),
            'qrMode' => true,
        ]);
    }
}
