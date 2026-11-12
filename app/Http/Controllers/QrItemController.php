<?php

namespace App\Http\Controllers;

use App\Application\Orders\CustomRebuildPresentation;
use App\Application\Orders\CustomRebuildTimeline;
use App\Enums\CustomRebuildAction;
use App\Enums\CustomRebuildState;
use App\Enums\ProcessThreadType;
use App\Enums\UserRole;
use App\Models\Item;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class QrItemController extends Controller
{
    public function __invoke(
        Request $request,
        string $randomToken,
        CustomRebuildPresentation $presentation,
        CustomRebuildTimeline $timeline,
    ): View {
        $item = Item::query()->where('qr_token', $randomToken)->firstOrFail();
        $order = $item->order;

        abort_unless($request->user()->can('view', $order), 404);

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
