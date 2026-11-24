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
