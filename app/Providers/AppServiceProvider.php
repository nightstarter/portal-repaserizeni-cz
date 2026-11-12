<?php

namespace App\Providers;

use App\Application\Orders\InitialProcessThreadFactory;
use App\Contracts\CreatesInitialProcessThreads;
use App\Integrations\LegacyShop\LegacySchemaInspector;
use App\Integrations\LegacyShop\LegacyShopDiagnosticService;
use App\Integrations\LegacyShop\LegacyShopMapping;
use App\Integrations\LegacyShop\PrestaShop14OrderReader;
use App\Models\Order;
use App\Models\User;
use App\Policies\OrderPolicy;
use App\Policies\UserPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(CreatesInitialProcessThreads::class, InitialProcessThreadFactory::class);
        $this->app->bind(LegacyShopMapping::class, fn (): LegacyShopMapping => LegacyShopMapping::fromConfig());
        $this->app->bind(LegacySchemaInspector::class, fn (): LegacySchemaInspector => new LegacySchemaInspector(DB::connection(PrestaShop14OrderReader::CONNECTION)));
        $this->app->bind(PrestaShop14OrderReader::class, fn (): PrestaShop14OrderReader => new PrestaShop14OrderReader(
            DB::connection(PrestaShop14OrderReader::CONNECTION),
            $this->app->make(LegacySchemaInspector::class),
        ));
        $this->app->bind(LegacyShopDiagnosticService::class, fn (): LegacyShopDiagnosticService => new LegacyShopDiagnosticService(
            $this->app->make(LegacyShopMapping::class),
            $this->app->make(LegacySchemaInspector::class),
            DB::connection(PrestaShop14OrderReader::CONNECTION),
        ));
    }

    public function boot(): void
    {
        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(Order::class, OrderPolicy::class);
        Gate::define('manage-users', fn (User $user): bool => $user->isAdministrator());

        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)->by(strtolower((string) $request->input('email')).'|'.$request->ip()));
    }
}
