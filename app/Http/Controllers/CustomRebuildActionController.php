<?php

namespace App\Http\Controllers;

use App\Application\Orders\CustomRebuildCommand;
use App\Application\Orders\CustomRebuildWorkflow;
use App\Enums\CustomRebuildAction;
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
            throw ValidationException::withMessages(['action' => 'Unknown custom rebuild action.']);
        }

        $idempotencyKey = (string) ($request->header('Idempotency-Key') ?: $request->input('idempotency_key', ''));
        $expectedVersion = $request->validate([
            'expected_lock_version' => ['nullable', 'integer', 'min:0'],
        ])['expected_lock_version'] ?? null;
        $data = $request->only([
            'inspection_result', 'condition_description', 'routing', 'fault_code',
            'fault_note', 'axial_rods', 'target_state', 'state', 'to_state', 'reason',
        ]);

        $event = $workflow->execute($order, new CustomRebuildCommand(
            $workflowAction,
            $request->user(),
            $idempotencyKey,
            $data,
            $expectedVersion === null ? null : (int) $expectedVersion,
        ));

        if ($request->expectsJson()) {
            return response()->json([
                'event_id' => $event->id,
                'action' => $event->action,
                'state' => $event->to_state,
                'lock_version' => $event->processThread->fresh()->lock_version,
            ]);
        }

        return redirect()->route('orders.show', $order);
    }
}
