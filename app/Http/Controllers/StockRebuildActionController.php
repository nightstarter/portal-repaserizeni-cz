<?php

namespace App\Http\Controllers;

use App\Application\Orders\StockRebuildCommand;
use App\Application\Orders\StockRebuildWorkflow;
use App\Enums\ProcessThreadType;
use App\Enums\StockRebuildAction;
use App\Exceptions\WorkflowConflictException;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class StockRebuildActionController extends Controller
{
    public function __invoke(
        Request $request,
        Order $order,
        string $threadType,
        string $action,
        StockRebuildWorkflow $workflow,
    ): JsonResponse|RedirectResponse {
        $this->authorize('view', $order);
        $type = ProcessThreadType::tryFrom($threadType);
        $workflowAction = StockRebuildAction::tryFrom($action);

        if ($type === null || $workflowAction === null || $workflowAction === StockRebuildAction::ADMIN_OVERRIDE) {
            throw ValidationException::withMessages(['action' => __('app.workflow_errors.unknown_stock_action')]);
        }

        $idempotencyKey = (string) ($request->header('Idempotency-Key') ?: $request->input('idempotency_key', ''));
        $expectedVersion = $request->validate([
            'expected_lock_version' => ['nullable', 'integer', 'min:0'],
        ])['expected_lock_version'] ?? null;

        try {
            $event = $workflow->execute($order, $type, new StockRebuildCommand(
                $workflowAction,
                $request->user(),
                $idempotencyKey,
                $request->only(['eta', 'state', 'to_state', 'target_state']),
                $expectedVersion === null ? null : (int) $expectedVersion,
            ));
        } catch (WorkflowConflictException $exception) {
            if ($request->expectsJson()) {
                throw $exception;
            }

            $state = $order->processThreads()->where('type', $type)->value('state');

            return $this->redirectTarget($request, $order)
                ->withErrors(['workflow' => __('app.workflow_conflict_current', [
                    'state' => $state === null ? '—' : __('app.thread_states.'.$state),
                ])]);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'event_id' => $event->id,
                'action' => $event->action,
                'state' => $event->to_state,
                'lock_version' => $event->processThread->fresh()->lock_version,
            ]);
        }

        return $this->redirectTarget($request, $order)->with('status', __('app.action_completed_state', [
            'state' => __('app.thread_states.'.$event->to_state),
        ]));
    }

    private function redirectTarget(Request $request, Order $order): RedirectResponse
    {
        $qrToken = $request->string('return_qr_token')->toString();

        if ($qrToken !== '' && $order->items()->where('qr_token', $qrToken)->exists()) {
            return redirect()->route('items.qr', $qrToken);
        }

        return redirect()->route('orders.show', $order);
    }
}
