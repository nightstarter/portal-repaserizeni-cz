<?php

namespace App\Contracts;

use App\Enums\WorkflowScenario;
use App\Models\Order;
use App\Models\ProcessThread;
use Illuminate\Database\Eloquent\Collection;

interface CreatesInitialProcessThreads
{
    /**
     * @return Collection<int, ProcessThread>
     */
    public function createFor(Order $order, WorkflowScenario $scenario): Collection;
}
