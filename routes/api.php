<?php

use App\Http\Controllers\Internal\V1\BridgeCommandController;
use App\Http\Controllers\Internal\V1\BridgeHeartbeatController;
use App\Http\Controllers\Internal\V1\StockSnapshotController;
use Illuminate\Support\Facades\Route;

Route::prefix('internal/v1')
    ->middleware(['bridge.enabled', 'throttle:pohoda-bridge', 'bridge.hmac'])
    ->group(function (): void {
        Route::post('/stock-snapshots', StockSnapshotController::class)->name('internal.v1.stock-snapshots.store');
        Route::get('/commands', [BridgeCommandController::class, 'index'])->name('internal.v1.commands.index');
        Route::post('/commands/{command}/result', [BridgeCommandController::class, 'result'])->name('internal.v1.commands.result');
        Route::post('/heartbeat', BridgeHeartbeatController::class)->name('internal.v1.heartbeat.store');
    });
