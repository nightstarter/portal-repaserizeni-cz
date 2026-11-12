<?php

namespace App\Http\Controllers;

use App\Enums\OrganizationCode;
use App\Models\Order;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function show(Request $request, Order $order): View
    {
        $this->authorize('view', $order);

        $order->load([
            'items',
            'processThreads.responsibleOrganization',
            'processThreads.events' => fn ($events) => $events->latest('performed_at'),
        ]);

        if ($request->user()->organization->code === OrganizationCode::AUTOKLUBY) {
            $order->load('customer');
        }

        return view('orders.show', compact('order'));
    }
}
