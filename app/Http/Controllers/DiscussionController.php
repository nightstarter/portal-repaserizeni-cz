<?php

namespace App\Http\Controllers;

use App\Application\Messages\DiscussionService;
use App\Application\Messages\PostMessageCommand;
use App\Exceptions\WorkflowConflictException;
use App\Models\Message;
use App\Models\Order;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class DiscussionController extends Controller
{
    public function index(Request $request, Order $order, DiscussionService $discussion): View
    {
        abort_unless($request->user()->can('view', $order), 404);
        $discussion->markRead($order, $request->user());
        $messages = $order->messages()
            ->with(['author:id,name', 'authorOrganization:id,name,code', 'attachments'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->cursorPaginate(30)
            ->withQueryString();

        return view('orders.discussion', [
            'order' => $order,
            'messages' => $messages,
            'messageFormKey' => (string) Str::uuid(),
        ]);
    }

    public function store(Request $request, Order $order, DiscussionService $discussion): RedirectResponse
    {
        abort_unless($request->user()->can('view', $order), 404);
        abort_unless($request->user()->can('create', [Message::class, $order]), 403, __('app.message_not_allowed'));
        $attachments = $request->file('attachments', []);
        if (! is_array($attachments)) {
            $attachments = [$attachments];
        }
        try {
            $discussion->post($order, new PostMessageCommand(
                $request->user(),
                (string) $request->input('body'),
                (string) $request->input('idempotency_key'),
                $attachments,
            ));
        } catch (WorkflowConflictException $exception) {
            return back()->withErrors(['message' => __('app.message_idempotency_conflict')])->withInput();
        }

        return redirect()->route('orders.discussion.index', $order)->with('status', __('app.message_posted'));
    }

    public function markRead(Request $request, Order $order, DiscussionService $discussion): RedirectResponse
    {
        abort_unless($request->user()->can('view', $order), 404);
        $discussion->markRead($order, $request->user());

        return back()->with('status', __('app.messages_marked_read'));
    }
}
