<?php

namespace App\Http\Controllers;

use App\Application\Orders\WarrantyCommand;
use App\Application\Orders\WarrantyWorkflow;
use App\Enums\OrganizationCode;
use App\Enums\ProcessThreadType;
use App\Enums\WarrantyAction;
use App\Exceptions\WorkflowConflictException;
use App\Models\Item;
use App\Models\Order;
use App\Models\ProcessThread;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class WarrantyController extends Controller
{
    public function store(Request $request, Order $order, Item $item, WarrantyWorkflow $workflow): JsonResponse|RedirectResponse
    {
        $this->assertVisible($request, $order);
        abort_unless($item->order_id === $order->id, 404);
        $validated = $request->validate([
            'expected_lock_version' => ['required', 'integer', 'min:0'],
            'reason' => ['required', 'string', 'max:2000'],
        ]);
        try {
            $thread = $workflow->create($order, $item, new WarrantyCommand(
                WarrantyAction::CREATE_CYCLE,
                $request->user(),
                $this->idempotencyKey($request),
                ['reason' => $validated['reason']],
                (int) $validated['expected_lock_version'],
            ));
        } catch (WorkflowConflictException $exception) {
            return $this->conflictResponse($request, $order, $item->activeWarrantyThread?->state);
        }

        return $this->response($request, $order, $thread);
    }

    public function action(Request $request, Order $order, ProcessThread $thread, string $action, WarrantyWorkflow $workflow): JsonResponse|RedirectResponse
    {
        $this->assertVisible($request, $order);
        abort_unless($thread->order_id === $order->id && $thread->type === ProcessThreadType::WARRANTY, 404);
        if ($request->user()->organization->code === OrganizationCode::POLAND) {
            abort_unless(ProcessThread::query()->visibleTo($request->user())->whereKey($thread->id)->exists(), 404);
        }
        $warrantyAction = WarrantyAction::tryFrom($action);
        if ($warrantyAction === null || $warrantyAction === WarrantyAction::CREATE_CYCLE) {
            throw ValidationException::withMessages(['action' => __('app.workflow_errors.unknown_action')]);
        }
        if (in_array($warrantyAction, [
            WarrantyAction::SEND_TO_POLAND,
            WarrantyAction::CONFIRM_POLAND_RECEIPT,
            WarrantyAction::SEND_TO_AUTOKLUBY,
            WarrantyAction::CONFIRM_AUTOKLUBY_RECEIPT,
        ], true)) {
            throw ValidationException::withMessages(['action' => __('app.warranty_shipment_action_required')]);
        }
        if ($warrantyAction === WarrantyAction::ADMIN_OVERRIDE) {
            $request->validate(['override_confirmed' => ['accepted']]);
        }
        $validated = $request->validate(['expected_lock_version' => ['required', 'integer', 'min:0']]);
        if (in_array($warrantyAction, [WarrantyAction::SEND_TO_CUSTOMER, WarrantyAction::CONFIRM_CUSTOMER_PICKUP], true)) {
            $request->validate(['confirmed' => ['accepted']]);
        }
        try {
            $event = $workflow->execute($thread, new WarrantyCommand(
                $warrantyAction,
                $request->user(),
                $this->idempotencyKey($request),
                $request->only(['target_state', 'state', 'to_state', 'reason']),
                (int) $validated['expected_lock_version'],
            ));
        } catch (WorkflowConflictException $exception) {
            return $this->conflictResponse($request, $order, $thread->fresh()->state);
        }

        return $this->response($request, $order, $event->processThread->fresh());
    }

    private function assertVisible(Request $request, Order $order): void
    {
        abort_unless(Order::query()->visibleTo($request->user())->whereKey($order->id)->exists(), 404);
        $this->authorize('view', $order);
    }

    private function idempotencyKey(Request $request): string
    {
        return (string) ($request->header('Idempotency-Key') ?: $request->input('idempotency_key', ''));
    }

    private function response(Request $request, Order $order, ProcessThread $thread): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json([
                'thread_id' => $thread->id,
                'cycle_number' => $thread->warranty_cycle,
                'state' => $thread->state,
                'lock_version' => $thread->lock_version,
            ]);
        }

        $qrToken = $request->string('return_qr_token')->toString();
        if ($qrToken !== '' && $order->items()->where('qr_token', $qrToken)->exists()) {
            return redirect()->route('items.qr', $qrToken)->with('status', __('app.action_completed'));
        }

        return redirect()->to(route('orders.show', $order).'#warranty-thread-'.$thread->id)->with('status', __('app.action_completed'));
    }

    private function conflictResponse(Request $request, Order $order, ?string $state): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            throw new WorkflowConflictException(__('app.warranty_concurrency_error'));
        }

        return redirect()->to(route('orders.show', $order).'#warranties')
            ->withErrors(['warranty' => __('app.warranty_concurrency_state', [
                'state' => $state === null ? '—' : __('app.thread_states.'.$state),
            ])]);
    }
}
