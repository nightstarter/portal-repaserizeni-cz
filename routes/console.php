<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('about:phase', function (): void {
    $this->info('Phase 1B: safe legacy integration layer (live mapping not verified)');
    $this->line('Legacy shop production import: '.(config('integrations.legacy_shop.enabled') ? 'ENABLED' : 'disabled'));
})->purpose('Show the currently implemented project phase');

Schedule::command('notifications:dispatch')
    ->everyMinute()
    ->withoutOverlapping(10);

Schedule::command('ops:scheduler-heartbeat')
    ->everyMinute()
    ->withoutOverlapping(5);

Schedule::command('ops:monitor --no-interaction')
    ->everyFiveMinutes()
    ->withoutOverlapping(10)
    ->when(fn (): bool => (bool) config('operations.monitoring.enabled'));

Schedule::command('backup:scheduled --no-interaction')
    ->dailyAt((string) config('backups.schedule_time', '02:30'))
    ->withoutOverlapping(max(1, (int) config('backups.schedule_lock_minutes', 120)))
    ->when(fn (): bool => (bool) config('backups.scheduled_enabled'));
