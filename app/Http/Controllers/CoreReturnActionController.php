<?php

namespace App\Http\Controllers;

use App\Application\Orders\CoreReturnCommand;
use App\Application\Orders\CoreReturnWorkflow;
use App\Enums\CoreReturnAction;
use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Enums\ProcessThreadType;
use App\Exceptions\WorkflowConflictException;
use App\Models\Document;
use App\Models\Order;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CoreReturnActionController extends Controller
{
    public function __invoke(Request $request, Order $order, string $action, CoreReturnWorkflow $workflow): RedirectResponse
    {
        abort_unless($request->user()->can('view', $order), 404);
        $workflowAction = CoreReturnAction::tryFrom($action);
        if ($workflowAction === null) {
            throw ValidationException::withMessages(['action' => __('app.workflow_errors.unknown_core_action')]);
        }
        $thread = $order->processThreads()->where('type', ProcessThreadType::CORE_RETURN)->sole();
        if ($workflowAction === CoreReturnAction::CONFIRM_CREDIT_PAID) {
            $hasCredit = Document::query()->where('process_thread_id', $thread->id)
                ->where('type', DocumentType::SUPPLIER_CREDIT_NOTE)->where('status', DocumentStatus::ACTIVE)->exists();
            if (! $hasCredit) {
                throw ValidationException::withMessages(['document' => __('app.credit_note_required')]);
            }
        }
        $validated = $request->validate([
            'idempotency_key' => ['required', 'string'], 'expected_lock_version' => ['required', 'integer', 'min:0'],
        ]);
        try {
            $workflow->execute($order, new CoreReturnCommand(
                $workflowAction, $request->user(), $validated['idempotency_key'],
                $request->only(['piece_result', 'transport_condition', 'accident', 'disassembled', 'corrosion', 'axial_rods', 'note', 'approved_return_amount', 'currency', 'reason', 'override_reason']),
                (int) $validated['expected_lock_version'],
            ));
        } catch (WorkflowConflictException $exception) {
            return back()->withErrors(['workflow' => __('app.workflow_conflict_current', ['state' => __('app.thread_states.'.$thread->fresh()->state)])]);
        }

        return back()->with('status', __('app.action_completed_state', ['state' => __('app.thread_states.'.$thread->fresh()->state)]));
    }
}
