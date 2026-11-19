<?php

namespace App\Http\Controllers;

use App\Application\Finance\MinorMoney;
use App\Application\Orders\BuybackCommand;
use App\Application\Orders\BuybackOrderService;
use App\Application\Orders\BuybackWorkflow;
use App\Application\Orders\CreateBuybackCommand;
use App\Enums\BuybackAction;
use App\Enums\OrganizationCode;
use App\Enums\ProcessThreadType;
use App\Enums\UserRole;
use App\Exceptions\WorkflowConflictException;
use App\Models\Order;
use App\Models\ProcessThread;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class BuybackController extends Controller
{
    public function create(Request $request): View
    {
        abort_unless($request->user()->organization->code === OrganizationCode::AUTOKLUBY
            && in_array($request->user()->role, [UserRole::AK_ADMIN, UserRole::AK_OPERATOR], true), 403);

        return view('buybacks.create', ['idempotencyKey' => (string) Str::uuid()]);
    }

    public function store(Request $request, BuybackOrderService $service): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'idempotency_key' => ['required', 'string', 'max:128'],
            'customer_name' => ['required', 'string', 'max:255'],
            'customer_email' => ['nullable', 'email', 'max:255'],
            'customer_phone' => ['nullable', 'string', 'max:64'],
            'product_code' => ['required', 'string', 'max:64'],
            'product_name' => ['nullable', 'string', 'max:255'],
            'technical_type' => ['nullable', 'string', 'max:32'],
            'routing' => ['nullable', 'string', 'max:16'],
            'internal_note' => ['nullable', 'string', 'max:2000'],
            'internal_offer_amount' => ['required', 'string', 'max:32'],
            'internal_offer_currency' => ['required', 'string', 'size:3'],
        ]);
        $data = collect($validated)->except(['idempotency_key', 'internal_offer_amount'])->all();
        $data['internal_offer_minor'] = MinorMoney::parse($validated['internal_offer_amount'], 'internal_offer_amount');
        $data['internal_offer_currency'] = strtoupper($validated['internal_offer_currency']);
        $order = $service->create(new CreateBuybackCommand(
            $request->user(), $validated['idempotency_key'], $data,
        ));
        $thread = $order->processThreads()->where('type', ProcessThreadType::BUYOUT)->firstOrFail();

        if (! $request->expectsJson()) {
            return redirect()->route('orders.show', $order)->with('status', __('app.buyback_created', ['order' => $order->order_no]));
        }

        return response()->json([
            'order_no' => $order->order_no,
            'thread_id' => $thread->id,
            'item_id' => $thread->item_id,
            'state' => $thread->state,
        ], 201);
    }

    public function action(Request $request, Order $order, ProcessThread $thread, string $action, BuybackWorkflow $workflow): JsonResponse|RedirectResponse
    {
        if ($request->user()->organization->code === OrganizationCode::POLAND
            && ! Order::query()->visibleTo($request->user())->whereKey($order->id)->exists()) {
            abort(404);
        }
        $this->authorize('view', $order);
        abort_unless($thread->order_id === $order->id && $thread->type === ProcessThreadType::BUYOUT, 404);
        if ($request->user()->organization->code === OrganizationCode::POLAND
            && ! ProcessThread::query()->visibleTo($request->user())->whereKey($thread->id)->exists()) {
            abort(404);
        }
        $validated = $request->validate([
            'idempotency_key' => ['required', 'string', 'max:128'],
            'expected_lock_version' => ['required', 'integer', 'min:0'],
            'amount_minor' => ['nullable', 'integer', 'min:0'],
            'amount' => ['nullable', 'string', 'max:32'],
            'currency' => ['nullable', 'string', 'size:3'],
            'reason' => ['nullable', 'string', 'max:2000'],
            'document_id' => ['nullable', 'integer'],
            'state' => ['prohibited'],
        ]);
        $domainAction = BuybackAction::tryFrom($action);
        abort_if($domainAction === null, 404);
        if (in_array($domainAction, [BuybackAction::SEND_TO_PL, BuybackAction::CONFIRM_PL_RECEIPT, BuybackAction::REQUIRE_INVOICE_TO_PL], true)) {
            throw ValidationException::withMessages(['action' => 'This transport action is available only through ShipmentService.']);
        }
        $data = collect($validated)->except(['idempotency_key', 'expected_lock_version', 'amount'])->all();
        if (isset($validated['amount'])) {
            $data['amount_minor'] = MinorMoney::parse($validated['amount']);
        }
        if (isset($data['currency'])) {
            $data['currency'] = strtoupper($data['currency']);
        }
        try {
            $event = $workflow->execute($thread, new BuybackCommand(
                $domainAction, $request->user(), $validated['idempotency_key'],
                $data,
                (int) $validated['expected_lock_version'],
            ));
        } catch (WorkflowConflictException $exception) {
            throw ValidationException::withMessages(['workflow' => $exception->getMessage()]);
        }

        if (! $request->expectsJson()) {
            return redirect()->route('orders.show', $order)->with('status', __('app.buyback_action_recorded'));
        }

        return response()->json([
            'event_id' => $event->id,
            'state' => $event->to_state,
            'lock_version' => $event->processThread()->value('lock_version'),
        ]);
    }
}
