<?php

namespace App\Application\Orders;

use App\Models\Order;

final class OrderCompletionService
{
    public function refresh(Order $order): void
    {
        $hasActiveRequiredThread = $order->processThreads()->whereNull('completed_at')->exists();

        $order->forceFill([
            'closed_at' => $hasActiveRequiredThread ? null : ($order->closed_at ?? now()),
        ])->save();
    }
}
