<?php

namespace App\Http\Controllers;

use App\Application\Orders\BuybackPresentation;
use App\Enums\BuybackState;
use App\Enums\OrganizationCode;
use App\Enums\ProcessThreadType;
use App\Models\Order;
use App\Models\ProcessThread;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class BuybackQueueController extends Controller
{
    public function __invoke(Request $request, BuybackPresentation $presentation): View
    {
        $this->authorize('viewAny', Order::class);
        $filter = (string) $request->query('filter', 'active');
        $allowed = ['active', 'draft', 'poland_offer', 'final_price', 'customer', 'item', 'retained', 'poland_transport', 'invoice', 'payment', 'completed'];
        if (! in_array($filter, $allowed, true)) {
            $filter = 'active';
        }
        $query = ProcessThread::query()->visibleTo($request->user())
            ->where('type', ProcessThreadType::BUYOUT)
            ->with(['order', 'item', 'responsibleOrganization', 'latestEvent', 'buybackPriceQuotes',
                'events.actorOrganization', 'shipmentItems.shipment', 'documents', 'financeEntries'])
            ->orderByDesc('updated_at')->orderByDesc('id');
        $this->filter($query, $filter);
        $threads = $query->paginate(100)->withQueryString();
        $rows = collect($threads->items())->map(fn ($thread) => $presentation->for($thread, $request->user()));
        $isAk = $request->user()->organization->code === OrganizationCode::AUTOKLUBY;

        return view('buybacks.index', compact('threads', 'rows', 'filter', 'allowed', 'isAk'));
    }

    private function filter(Builder $query, string $filter): void
    {
        $states = match ($filter) {
            'draft' => [BuybackState::DRAFT],
            'poland_offer' => [BuybackState::WAITING_FOR_PL_OFFER, BuybackState::PL_OFFERED],
            'final_price' => [BuybackState::WAITING_FOR_FINAL_PRICE],
            'customer' => [BuybackState::FINAL_PRICE_APPROVED, BuybackState::CUSTOMER_OFFER_READY, BuybackState::OFFER_SENT],
            'item' => [BuybackState::CUSTOMER_ACCEPTED, BuybackState::ITEM_RECEIVED_AK, BuybackState::ITEM_CHECKED_AK],
            'retained' => [BuybackState::RETAINED_AK],
            'poland_transport' => [BuybackState::READY_TO_PL, BuybackState::SENT_TO_PL, BuybackState::RECEIVED_PL],
            'invoice' => [BuybackState::INVOICE_TO_PL_REQUIRED],
            'payment' => [BuybackState::WAITING_FOR_PL_PAYMENT],
            'completed' => [BuybackState::RETAINED_AK, BuybackState::PAID_BY_PL],
            default => [],
        };
        if ($filter === 'active') {
            $query->whereNull('completed_at');
        } elseif ($states !== []) {
            $query->whereIn('state', array_map(fn ($state) => $state->value, $states));
        }
    }
}
