<?php

namespace App\Http\Controllers;

use App\Application\Orders\CustomRebuildCommand;
use App\Application\Orders\CustomRebuildWorkflow;
use App\Enums\CustomRebuildAction;
use App\Enums\ProcessThreadType;
use App\Exceptions\WorkflowConflictException;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CustomRebuildActionController extends Controller
{
    public function __invoke(
        Request $request,
        Order $order,
        string $action,
        CustomRebuildWorkflow $workflow,
    ): JsonResponse|RedirectResponse {
        $this->authorize('view', $order);
        $workflowAction = CustomRebuildAction::tryFrom($action);

        if ($workflowAction === null) {
            throw ValidationException::withMessages(['action' => __('app.workflow_errors.unknown_action')]);
        }

        if ($workflowAction === CustomRebuildAction::ADMIN_OVERRIDE) {
            $request->validate(['override_confirmed' => ['accepted']]);
        }

        $idempotencyKey = (string) ($request->header('Idempotency-Key') ?: $request->input('idempotency_key', ''));
        $expectedVersion = $request->validate([
            'expected_lock_version' => ['nullable', 'integer', 'min:0'],
        ])['expected_lock_version'] ?? null;
        $data = $request->only([
            'inspection_result', 'condition_description', 'routing', 'fault_code',
            'fault_note', 'axial_rods', 'target_state', 'state', 'to_state', 'reason',
        ]);

        try {
            $event = $workflow->execute($order, new CustomRebuildCommand(
                $workflowAction,
                $request->user(),
                $idempotencyKey,
                $data,
                $expectedVersion === null ? null : (int) $expectedVersion,
            ));
        } catch (WorkflowConflictException $exception) {
            if ($request->expectsJson()) {
                throw $exception;
            }

            $state = $order->processThreads()
                ->where('type', ProcessThreadType::CUSTOM_REBUILD)
                ->value('state');

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

        return $this->redirectTarget($request, $order)
            ->with('status', __('app.action_completed'));
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
