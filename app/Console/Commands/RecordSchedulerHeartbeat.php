<?php

namespace App\Console\Commands;

use App\Models\SchedulerHeartbeat;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class RecordSchedulerHeartbeat extends Command
{
    protected $signature = 'ops:scheduler-heartbeat';

    protected $description = 'Record a safe Laravel scheduler heartbeat';

    public function handle(): int
    {
        if (! Schema::hasTable('scheduler_heartbeats')) {
            return self::SUCCESS;
        }
        SchedulerHeartbeat::query()->updateOrCreate(['key' => 'laravel'], [
            'scheduler_seen_at' => now(),
            'monitor_enabled' => (bool) config('operations.monitoring.enabled'),
            'updated_at' => now(),
        ]);

        return self::SUCCESS;
    }
}
