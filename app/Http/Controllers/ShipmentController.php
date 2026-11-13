<?php

namespace App\Http\Controllers;

use App\Application\Shipments\ShipmentCommand;
use App\Application\Shipments\ShipmentService;
use App\Enums\OrganizationCode;
use App\Enums\ShipmentDirection;
use App\Enums\ShipmentStatus;
use App\Enums\UserRole;
use App\Exceptions\WorkflowConflictException;
use App\Models\Shipment;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ShipmentController extends Controller
{
    public function index(Request $request): View
    {
        $organization = $request->user()->organization;
        $shipments = Shipment::query()
            ->where(fn ($query) => $query
                ->where('sender_organization_id', $organization->id)
                ->orWhere('recipient_organization_id', $organization->id))
            ->withCount('shipmentItems')
            ->latest()
            ->paginate(30);

        $canCreate = $this->isOperationalUser($request);

        return view('shipments.index', compact('shipments', 'canCreate'));
    }

    public function create(Request $request): View
    {
        $code = $request->user()->organization->code;
        abort_unless(in_array($request->user()->role, [UserRole::AK_ADMIN, UserRole::AK_OPERATOR, UserRole::PL_MANAGER, UserRole::PL_OPERATOR], true), 403);

        $directions = $code === OrganizationCode::POLAND
            ? [ShipmentDirection::PL_TO_AK]
            : [ShipmentDirection::AK_TO_PL];
        if ($request->user()->role === UserRole::AK_ADMIN) {
            $directions = ShipmentDirection::cases();
        }

        return view('shipments.create', compact('directions'));
    }

    public function store(Request $request, ShipmentService $service): RedirectResponse
    {
        $validated = $request->validate([
            'direction' => ['required', 'string'],
            'carrier' => ['required', 'string'],
            'tracking' => ['nullable', 'string', 'max:128'],
            'reason' => ['nullable', 'string', 'max:2000'],
            'idempotency_key' => ['required', 'string', 'max:128'],
        ]);

        try {
            $shipment = $service->create(new ShipmentCommand(
                $request->user(),
                $validated['idempotency_key'],
                $request->only(['direction', 'carrier', 'tracking', 'reason']),
            ));
        } catch (WorkflowConflictException $exception) {
            return back()->withInput()->withErrors(['shipment' => __('app.shipment_conflict')]);
        }

        return redirect()->route('shipments.show', $shipment)->with('status', __('app.shipment_created'));
    }

    public function show(Request $request, Shipment $shipment): View
    {
        $this->assertParticipant($request, $shipment);
        $shipment->load([
            'senderOrganization', 'recipientOrganization', 'creator',
            'shipmentItems.item.order', 'shipmentItems.processThread',
            'events' => fn ($query) => $query->with('actorUser')->latest(),
        ]);

        $organizationCode = $request->user()->organization->code;
        $operational = $this->isOperationalUser($request);
        $canEdit = $operational && $shipment->status === ShipmentStatus::PREPARED && $organizationCode === $shipment->direction->sender();
        $canReceive = in_array($shipment->status, [ShipmentStatus::SENT, ShipmentStatus::TRANSPORT_ERROR], true)
            && $operational && $organizationCode === $shipment->direction->recipient();
        $canReportError = $operational && $shipment->status === ShipmentStatus::SENT;

        return view('shipments.show', [
            'shipment' => $shipment,
            'canEdit' => $canEdit,
            'canReceive' => $canReceive,
            'canReportError' => $canReportError,
            'formKeys' => [
                'add' => (string) Str::uuid(),
                'send' => (string) Str::uuid(),
                'deliver' => (string) Str::uuid(),
                'error' => (string) Str::uuid(),
            ],
        ]);
    }

    private function assertParticipant(Request $request, Shipment $shipment): void
    {
        abort_unless(in_array($request->user()->organization_id, [
            $shipment->sender_organization_id,
            $shipment->recipient_organization_id,
        ], true), 404);
    }

    private function isOperationalUser(Request $request): bool
    {
        return match ($request->user()->organization->code) {
            OrganizationCode::AUTOKLUBY => in_array($request->user()->role, [UserRole::AK_ADMIN, UserRole::AK_OPERATOR], true),
            OrganizationCode::POLAND => in_array($request->user()->role, [UserRole::PL_MANAGER, UserRole::PL_OPERATOR], true),
        };
    }
}
