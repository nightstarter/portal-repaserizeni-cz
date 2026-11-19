<?php

namespace App\Http\Controllers;

use App\Application\Messages\DiscussionNotificationAdminService;
use App\Domain\Buybacks\BuybackCustomerOfferQueued;
use App\Domain\Messages\MessagePosted;
use App\Models\OutboxEvent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class AdminNotificationController extends Controller
{
    public function index(): View
    {
        Gate::authorize('manage-notifications');
        $base = OutboxEvent::query()
            ->whereIn('event_type', [MessagePosted::TYPE, BuybackCustomerOfferQueued::TYPE]);
        $counts = collect([
            OutboxEvent::PENDING,
            OutboxEvent::PROCESSING,
            OutboxEvent::SENT,
            OutboxEvent::FAILED,
        ])->mapWithKeys(fn (string $status): array => [$status => (clone $base)->where('status', $status)->count()]);
        $events = (clone $base)
            ->with(['recipient:id,email', 'message.order:id,order_no', 'buybackThread.order:id,order_no'])
            ->orderByDesc('id')
            ->paginate(50);

        return view('admin.notifications.index', [
            'counts' => $counts,
            'events' => $events,
            'lastSentAt' => (clone $base)->where('status', OutboxEvent::SENT)->max('sent_at'),
            'deliveryEnabled' => [
                MessagePosted::TYPE => (bool) config('discussion_notifications.enabled'),
                BuybackCustomerOfferQueued::TYPE => (bool) config('buyback.customer_email_enabled'),
            ],
        ]);
    }

    public function retry(Request $request, OutboxEvent $event, DiscussionNotificationAdminService $service): RedirectResponse
    {
        Gate::authorize('manage-notifications');
        $retried = $service->retry($event, $request->user());

        return back()->with('status', __($retried ? 'app.notification_retry_queued' : 'app.notification_retry_unchanged'));
    }
}
