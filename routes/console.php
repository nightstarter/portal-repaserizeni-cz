<?php

use Illuminate\Support\Facades\Artisan;

Artisan::command('about:phase', function (): void {
    $this->info('Phase 1B: safe legacy integration layer (live mapping not verified)');
    $this->line('Legacy shop production import: '.(config('integrations.legacy_shop.enabled') ? 'ENABLED' : 'disabled'));
})->purpose('Show the currently implemented project phase');
