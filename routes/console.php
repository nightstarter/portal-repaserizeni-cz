<?php

use Illuminate\Support\Facades\Artisan;

Artisan::command('about:phase', function (): void {
    $this->info('Phase 0: bootstrap and secure foundation');
    $this->line('Legacy shop production import: '.(config('integrations.legacy_shop.enabled') ? 'ENABLED' : 'disabled'));
})->purpose('Show the currently implemented project phase');
