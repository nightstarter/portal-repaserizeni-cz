<?php

namespace App\Http\Controllers;

use App\Application\Shipments\ShipmentCommand;
use App\Application\Shipments\ShipmentService;
use App\Enums\ShipmentAction;
use App\Exceptions\WorkflowConflictException;
use App\Models\Shipment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ShipmentActionController extends Controller
{
    public function __invoke(Request $request, Shipment $shipment, string $action, ShipmentService $service): RedirectResponse
    {
        abort_unless(in_array($request->user()->organization_id, [$shipment->sender_organization_id, $shipment->recipient_organization_id], true), 404);
        $shipmentAction = ShipmentAction::tryFrom($action);
        if (! in_array($shipmentAction, [ShipmentAction::SEND_SHIPMENT, ShipmentAction::CONFIRM_SHIPMENT_DELIVERY, ShipmentAction::MARK_TRANSPORT_ERROR], true)) {
            throw ValidationException::withMessages(['action' => __('app.shipment_unknown_action')]);
        }
        $validated = $request->validate([
            'idempotency_key' => ['required', 'string', 'max:128'],
            'expected_lock_version' => ['required', 'integer', 'min:0'],
            'tracking' => ['nullable', 'string', 'max:128'],
            'description' => ['nullable', 'string', 'max:5000'],
        ]);
        $command = new ShipmentCommand(
            $request->user(),
            $validated['idempotency_key'],
            $request->only(['tracking', 'description']),
            (int) $validated['expected_lock_version'],
        );

        try {
            match ($shipmentAction) {
                ShipmentAction::SEND_SHIPMENT => $service->send($shipment, $command),
                ShipmentAction::CONFIRM_SHIPMENT_DELIVERY => $service->confirmDelivery($shipment, $command),
                ShipmentAction::MARK_TRANSPORT_ERROR => $service->markTransportError($shipment, $command),
                default => throw new \LogicException('Unsupported shipment action.'),
            };
        } catch (WorkflowConflictException $exception) {
            return redirect()->route('shipments.show', $shipment)->withErrors(['shipment' => __('app.shipment_conflict')]);
        }

        return redirect()->route('shipments.show', $shipment)->with('status', __('app.shipment_action_completed'));
    }
}
