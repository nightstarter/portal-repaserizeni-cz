<?php

namespace Tests\Feature;

use Tests\TestCase;

class PhaseTwoAcceptanceTest extends TestCase
{
    public function test_mutating_web_route_rejects_missing_csrf_outside_test_bypass(): void
    {
        $this->app->detectEnvironment(static fn (): string => 'local');

        try {
            $this->put(route('locale.update'), ['locale' => 'cs'])->assertStatus(419);
        } finally {
            $this->app->detectEnvironment(static fn (): string => 'testing');
        }
    }

    public function test_login_and_qr_routes_exist_but_no_browser_stock_decision_route_exists(): void
    {
        $routes = collect(app('router')->getRoutes()->getRoutes());

        self::assertTrue($routes->contains(fn ($route): bool => $route->getName() === 'login' && in_array('GET', $route->methods(), true)));
        self::assertTrue($routes->contains(fn ($route): bool => $route->getName() === 'items.qr' && $route->uri() === 'q/{randomToken}'));
        self::assertFalse($routes->contains(fn ($route): bool => str_contains($route->uri(), 'stock-decision')));
        self::assertFalse($routes->contains(fn ($route): bool => str_contains((string) $route->getActionName(), 'StockBranchDecisionService')));
    }

    public function test_acceptance_suite_is_isolated_from_production_writes(): void
    {
        self::assertSame('testing', app()->environment());
        self::assertSame('sqlite', config('database.default'));
        self::assertSame(':memory:', config('database.connections.sqlite.database'));
        self::assertFalse(config('integrations.legacy_shop.enabled'));
        self::assertFalse(config('integrations.pohoda_bridge.enabled'));
    }
}
