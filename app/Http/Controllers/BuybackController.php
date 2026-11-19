<?php

namespace App\Http\Controllers;

use App\Application\Orders\BuybackCommand;
use App\Application\Orders\BuybackOrderService;
use App\Application\Orders\BuybackWorkflow;
use App\Application\Orders\CreateBuybackCommand;
use App\Enums\BuybackAction;
use App\Enums\OrganizationCode;
use App\Enums\ProcessThreadType;
use App\Exceptions\WorkflowConflictException;
use App\Models\Order;
use App\Models\ProcessThread;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class BuybackController extends Controller
{
    public function store(Request $request, BuybackOrderService $service): JsonResponse
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
        ]);
        $order = $service->create(new CreateBuybackCommand(
            $request->user(), $validated['idempotency_key'], collect($validated)->except('idempotency_key')->all(),
        ));
        $thread = $order->processThreads()->where('type', ProcessThreadType::BUYOUT)->firstOrFail();

        return response()->json([
            'order_no' => $order->order_no,
            'thread_id' => $thread->id,
            'item_id' => $thread->item_id,
            'state' => $thread->state,
        ], 201);
    }

    public function action(Request $request, Order $order, ProcessThread $thread, string $action, BuybackWorkflow $workflow): JsonResponse
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
        try {
            $event = $workflow->execute($thread, new BuybackCommand(
                $domainAction, $request->user(), $validated['idempotency_key'],
                collect($validated)->except(['idempotency_key', 'expected_lock_version'])->all(),
                (int) $validated['expected_lock_version'],
            ));
        } catch (WorkflowConflictException $exception) {
            throw ValidationException::withMessages(['workflow' => $exception->getMessage()]);
        }

        return response()->json([
            'event_id' => $event->id,
            'state' => $event->to_state,
            'lock_version' => $event->processThread()->value('lock_version'),
        ]);
    }
}
