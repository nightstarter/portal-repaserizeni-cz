<?php

namespace App\Http\Controllers;

use App\Application\Orders\PhaseFourQueuePresentation;
use App\Enums\ItemRole;
use App\Enums\ProcessThreadType;
use App\Models\Order;
use App\Models\ProcessThread;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class ReturnController extends Controller
{
    private const FILTERS = ['all', 'autokluby', 'poland', 'credit_note', 'payment'];

    public function __invoke(Request $request, PhaseFourQueuePresentation $presentation): View
    {
        $this->authorize('viewAny', Order::class);
        $user = $request->user();
        $activeFilter = in_array($request->query('filter'), self::FILTERS, true)
            ? (string) $request->query('filter')
            : 'all';
        $base = ProcessThread::query()
            ->visibleTo($user)
            ->where('type', ProcessThreadType::CORE_RETURN);

        $filterCounts = collect(self::FILTERS)->mapWithKeys(fn (string $filter): array => [
            $filter => $this->applyFilter(clone $base, $filter)->count(),
        ]);
        $threads = $this->applyFilter(clone $base, $activeFilter)
            ->with([
                'order:id,order_no,product_code',
                'order.items' => fn ($items) => $items
                    ->where('role', ItemRole::CORE_RETURN)
                    ->select(['id', 'order_id', 'role', 'current_location']),
                'responsibleOrganization:id,name',
                'financeEntries' => fn ($entries) => $entries->effective()->orderBy('id'),
            ])
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        return view('returns.index', [
            'rows' => $threads->map(fn (ProcessThread $thread): array => $presentation->row($thread)),
            'filters' => self::FILTERS,
            'filterCounts' => $filterCounts,
            'activeFilter' => $activeFilter,
        ]);
    }

    private function applyFilter(Builder $query, string $filter): Builder
    {
        return match ($filter) {
            'autokluby' => $query->whereIn('state', ['WAITING_CORE', 'CORE_RECEIVED_AK']),
            'poland' => $query->whereIn('state', ['SENT_TO_PL', 'CORE_INSPECTION']),
            'credit_note' => $query->whereIn('state', ['DEPOSIT_FULL_APPROVED', 'DEPOSIT_REDUCED_APPROVED']),
            'payment' => $query->where('state', 'CREDIT_ISSUED'),
            default => $query,
        };
    }
}
