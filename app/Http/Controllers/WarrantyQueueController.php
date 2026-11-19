<?php

namespace App\Http\Controllers;

use App\Application\Orders\WarrantyPresentation;
use App\Enums\ProcessThreadType;
use App\Enums\WarrantyState;
use App\Models\Order;
use App\Models\ProcessThread;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class WarrantyQueueController extends Controller
{
    public function __invoke(Request $request, WarrantyPresentation $presentation): View
    {
        $this->authorize('viewAny', Order::class);
        $filters = ['active', 'autokluby', 'poland', 'ready', 'transport', 'repair', 'completed'];
        $filter = in_array($request->query('filter'), $filters, true) ? (string) $request->query('filter') : 'active';
        $query = ProcessThread::query()
            ->visibleTo($request->user())
            ->where('type', ProcessThreadType::WARRANTY)
            ->with([
                'order', 'item', 'responsibleOrganization', 'latestEvent',
                'events.actorUser', 'events.actorOrganization', 'shipmentItems.shipment',
            ]);
        $this->applyFilter($query, $filter);
        $threads = $query
            ->orderByDesc(ProcessThread::select('performed_at')
                ->from('thread_events')
                ->whereColumn('thread_events.process_thread_id', 'process_threads.id')
                ->latest('performed_at')
                ->limit(1))
            ->orderByDesc('process_threads.id')
            ->paginate(100)
            ->withQueryString();

        return view('warranties.index', [
            'rows' => $presentation->collection($threads->getCollection(), $request->user()),
            'threads' => $threads,
            'filters' => $filters,
            'activeFilter' => $filter,
        ]);
    }

    private function applyFilter(Builder $query, string $filter): void
    {
        match ($filter) {
            'active' => $query->whereNull('completed_at'),
            'autokluby' => $query->whereNull('completed_at')->where('responsible_party', 'AUTOKLUBY'),
            'poland' => $query->whereNull('completed_at')->where('responsible_party', 'POLAND'),
            'ready' => $query->whereIn('state', [WarrantyState::READY_TO_PL, WarrantyState::READY_TO_AK]),
            'transport' => $query->whereIn('state', [WarrantyState::SENT_TO_PL, WarrantyState::SENT_TO_AK]),
            'repair' => $query->whereIn('state', [WarrantyState::RECEIVED_PL, WarrantyState::REPAIR_COMPLETED]),
            'completed' => $query->whereNotNull('completed_at'),
        };
    }
}
