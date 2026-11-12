<?php

namespace App\Http\Controllers;

use App\Enums\OrganizationCode;
use App\Models\IntegrationEvent;
use App\Models\Order;
use App\Models\Organization;
use App\Models\ProcessThread;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        $user = $request->user();
        $this->authorize('viewAny', Order::class);

        $visibleOrders = Order::query()->visibleTo($user);
        $visibleThreads = ProcessThread::query()
            ->whereNull('completed_at')
            ->whereHas('order', fn (Builder $orders) => $orders->visibleTo($user));
        $polandId = Organization::query()->where('code', OrganizationCode::POLAND)->value('id');

        $orders = (clone $visibleOrders)
            ->with(['processThreads.responsibleOrganization'])
            ->latest()
            ->limit(50)
            ->get();

        return view('dashboard', [
            'orders' => $orders,
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
        ]);
    }
}
