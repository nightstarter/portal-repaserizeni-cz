<?php

namespace App\Http\Controllers;

use App\Application\Orders\CustomRebuildPresentation;
use App\Enums\OrderType;
use App\Enums\OrganizationCode;
use App\Enums\ProcessThreadType;
use App\Models\IntegrationEvent;
use App\Models\Order;
use App\Models\Organization;
use App\Models\ProcessThread;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(Request $request, CustomRebuildPresentation $presentation): View
    {
        $user = $request->user();
        $this->authorize('viewAny', Order::class);

        $visibleOrders = $user->organization->code === OrganizationCode::AUTOKLUBY
            ? Order::query()->visibleTo($user)
            : Order::query()->customRebuildVisibleTo($user);
        $visibleThreads = ProcessThread::query()
            ->where('type', ProcessThreadType::CUSTOM_REBUILD)
            ->whereNull('completed_at')
            ->whereHas('order', fn (Builder $orders) => $orders->customRebuildVisibleTo($user));
        $polandId = Organization::query()->where('code', OrganizationCode::POLAND)->value('id');

        $orders = (clone $visibleOrders)
            ->with(['processThreads.responsibleOrganization', 'processThreads.events'])
            ->latest()
            ->limit(50)
            ->get();
        $rows = $orders->map(fn (Order $order): array => [
            'order' => $order,
            'workflow' => $order->order_type === OrderType::CUSTOM_REBUILD
                ? $presentation->for($order, $user)
                : null,
        ]);

        return view('dashboard', [
            'rows' => $rows,
            'totalOrders' => (clone $visibleOrders)->count(),
            'waitingForUs' => (clone $visibleThreads)
                ->where('responsible_organization_id', $user->organization_id)
                ->count(),
            'waitingForPoland' => $polandId === null
                ? 0
                : (clone $visibleThreads)->where('responsible_organization_id', $polandId)->count(),
            'integrationErrors' => $user->organization->code === OrganizationCode::AUTOKLUBY
                ? IntegrationEvent::query()->where('status', 'FAILED')->count()
                : 0,
            'isPoland' => $user->organization->code === OrganizationCode::POLAND,
        ]);
    }
}
