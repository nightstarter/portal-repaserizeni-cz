<?php

namespace App\Http\Controllers;

use App\Application\Orders\PhaseFourQueuePresentation;
use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Enums\FinanceEntryType;
use App\Enums\ItemRole;
use App\Enums\ProcessThreadType;
use App\Models\Order;
use App\Models\ProcessThread;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class FinanceController extends Controller
{
    public function __invoke(Request $request, PhaseFourQueuePresentation $presentation): View
    {
        $this->authorize('viewAny', Order::class);
        $user = $request->user();
        $business = ProcessThread::query()->visibleTo($user)->whereIn('type', [
            ProcessThreadType::CUSTOM_REBUILD,
            ProcessThreadType::STOCK_REQUEST,
            ProcessThreadType::STOCK_REPLENISHMENT,
        ]);
        $core = ProcessThread::query()->visibleTo($user)->where('type', ProcessThreadType::CORE_RETURN);

        $queries = [
            'missing_invoice' => (clone $business)->whereDoesntHave('documents', fn (Builder $documents) => $documents
                ->where('type', DocumentType::SUPPLIER_INVOICE)
                ->where('status', DocumentStatus::ACTIVE)),
            'waiting_deposit_decision' => (clone $core)->where('state', 'CORE_INSPECTION'),
            'missing_credit_note' => (clone $core)->whereIn('state', ['DEPOSIT_FULL_APPROVED', 'DEPOSIT_REDUCED_APPROVED'])
                ->whereDoesntHave('documents', fn (Builder $documents) => $documents
                    ->where('type', DocumentType::SUPPLIER_CREDIT_NOTE)
                    ->where('status', DocumentStatus::ACTIVE)),
            'waiting_payment' => (clone $core)->where('state', 'CREDIT_ISSUED'),
            'reduced_settlement' => (clone $core)->whereHas('financeEntries', fn (Builder $entries) => $entries
                ->effective()
                ->where('type', FinanceEntryType::SUPPLIER_DEPOSIT_REDUCTION)),
        ];

        $sections = collect($queries)->map(function (Builder $query) use ($presentation) {
            return $query->with([
                'order:id,order_no,product_code',
                'order.items' => fn ($items) => $items
                    ->where('role', ItemRole::CORE_RETURN)
                    ->select(['id', 'order_id', 'role', 'current_location']),
                'responsibleOrganization:id,name',
                'financeEntries' => fn ($entries) => $entries->effective()->orderBy('id'),
            ])->orderByDesc('id')->limit(100)->get()
                ->map(fn (ProcessThread $thread): array => $presentation->row($thread));
        });

        return view('finance.index', ['sections' => $sections]);
    }
}
