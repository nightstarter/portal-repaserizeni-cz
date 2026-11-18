<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class DiscussionQueueController extends Controller
{
    public function __invoke(Request $request): View
    {
        $this->authorize('viewAny', Order::class);
        $user = $request->user();
        $orders = Order::query()
            ->visibleTo($user)
            ->whereHas('messages')
            ->withCount(['messages as unread_count' => fn ($messages) => $messages->unreadFor($user)])
            ->withMax('messages as last_message_at', 'created_at')
            ->with([
                'latestMessage.author:id,name',
                'latestMessage.authorOrganization:id,name,code',
            ])
            ->orderByDesc('unread_count')
            ->orderByDesc('last_message_at')
            ->orderByDesc('orders.id')
            ->paginate(50);

        return view('discussions.index', ['orders' => $orders]);
    }
}
