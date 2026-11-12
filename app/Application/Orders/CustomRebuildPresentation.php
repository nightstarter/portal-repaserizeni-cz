<?php

namespace App\Application\Orders;

use App\Enums\CustomRebuildAction;
use App\Enums\ProcessThreadType;
use App\Models\Order;
use App\Models\ProcessThread;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;

final class CustomRebuildPresentation
{
    public function __construct(private readonly CustomRebuildActionGuard $actionGuard) {}

    /**
     * @return array{
     *   thread: ProcessThread,
     *   allowed_actions: array<int, CustomRebuildAction>,
     *   next_action: ?CustomRebuildAction,
     *   last_event_at: ?CarbonImmutable
     * }
     */
    public function for(Order $order, User $user): array
    {
        $thread = $order->relationLoaded('processThreads')
            ? $order->processThreads->firstWhere('type', ProcessThreadType::CUSTOM_REBUILD)
            : $order->processThreads()->where('type', ProcessThreadType::CUSTOM_REBUILD)->first();

        if (! $thread instanceof ProcessThread) {
            throw (new ModelNotFoundException)->setModel(ProcessThread::class);
        }

        if (! $thread->relationLoaded('events')) {
            $thread->load('events');
        }

        $allowedActions = $this->actionGuard->allowedActions($order, $thread, $user);
        $lastEvent = $thread->events->sortByDesc('id')->first();

        return [
            'thread' => $thread,
            'allowed_actions' => $allowedActions,
            'next_action' => $allowedActions[0] ?? null,
            'last_event_at' => $lastEvent?->performed_at,
        ];
    }
}
