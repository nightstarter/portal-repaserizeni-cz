<?php

namespace App\Http\Controllers;

use App\Application\Shipments\ShipmentCommand;
use App\Application\Shipments\ShipmentService;
use App\Enums\ItemRole;
use App\Enums\ProcessThreadType;
use App\Exceptions\WorkflowConflictException;
use App\Models\Item;
use App\Models\Shipment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ShipmentItemController extends Controller
{
    public function store(Request $request, Shipment $shipment, ShipmentService $service): RedirectResponse
    {
        $this->assertParticipant($request, $shipment);
        $validated = $request->validate([
            'qr_token' => ['required', 'string', 'max:255'],
            'idempotency_key' => ['required', 'string', 'max:128'],
            'expected_lock_version' => ['required', 'integer', 'min:0'],
        ]);
        $token = $this->normalizeQrToken($validated['qr_token']);
        $item = Item::query()->where('qr_token', $token)->first();
        if ($item === null) {
            throw ValidationException::withMessages(['qr_token' => __('app.shipment_qr_not_found')]);
        }
        $threadType = match ($item->role) {
            ItemRole::CUSTOMER_ITEM => ProcessThreadType::CUSTOM_REBUILD,
            ItemRole::STOCK_ITEM => ProcessThreadType::STOCK_REQUEST,
            ItemRole::STOCK_REPLACEMENT => ProcessThreadType::STOCK_REPLENISHMENT,
            default => null,
        };
        $thread = $threadType === null ? null : $item->order->processThreads()->where('type', $threadType)->first();
        if ($thread === null) {
            throw ValidationException::withMessages(['qr_token' => __('app.shipment_item_not_eligible')]);
        }

        try {
            $service->addItem($shipment, $item, $thread, new ShipmentCommand(
                $request->user(), $validated['idempotency_key'], [], (int) $validated['expected_lock_version'],
            ));
        } catch (WorkflowConflictException $exception) {
            return redirect()->route('shipments.show', $shipment)->withErrors(['shipment' => __('app.shipment_conflict')]);
        }

        return redirect()->route('shipments.show', $shipment)->with('status', __('app.shipment_item_added'));
    }

    public function destroy(Request $request, Shipment $shipment, Item $item, ShipmentService $service): RedirectResponse
    {
        $this->assertParticipant($request, $shipment);
        $validated = $request->validate([
            'idempotency_key' => ['required', 'string', 'max:128'],
            'expected_lock_version' => ['required', 'integer', 'min:0'],
        ]);
        $link = $shipment->shipmentItems()->where('item_id', $item->id)->firstOrFail();
        try {
            $service->removeItem($shipment, $item, $link->processThread, new ShipmentCommand(
                $request->user(), $validated['idempotency_key'], [], (int) $validated['expected_lock_version'],
            ));
        } catch (WorkflowConflictException $exception) {
            return redirect()->route('shipments.show', $shipment)->withErrors(['shipment' => __('app.shipment_conflict')]);
        }

        return redirect()->route('shipments.show', $shipment)->with('status', __('app.shipment_item_removed'));
    }

    private function normalizeQrToken(string $value): string
    {
        $value = trim($value);
        if (preg_match('~/q/([^/?#]+)~', $value, $matches) === 1) {
            return $matches[1];
        }

        return $value;
    }

    private function assertParticipant(Request $request, Shipment $shipment): void
    {
        abort_unless(in_array($request->user()->organization_id, [$shipment->sender_organization_id, $shipment->recipient_organization_id], true), 404);
    }
}
