<?php

namespace App\Providers;

use App\Application\Messages\DiscussionService;
use App\Application\Orders\InitialProcessThreadFactory;
use App\Application\Security\DatabaseCommandGuard;
use App\Contracts\CreatesInitialProcessThreads;
use App\Contracts\ResolvesStockAvailability;
use App\Enums\OrganizationCode;
use App\Integrations\LegacyShop\LegacySchemaInspector;
use App\Integrations\LegacyShop\LegacyShopDiagnosticService;
use App\Integrations\LegacyShop\LegacyShopMapping;
use App\Integrations\LegacyShop\PrestaShop14OrderReader;
use App\Integrations\Stock\PendingStockAvailabilityResolver;
use App\Models\Document;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\Order;
use App\Models\User;
use App\Policies\DocumentPolicy;
use App\Policies\MessageAttachmentPolicy;
use App\Policies\MessagePolicy;
use App\Policies\OrderPolicy;
use App\Policies\UserPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(CreatesInitialProcessThreads::class, InitialProcessThreadFactory::class);
        $this->app->bind(ResolvesStockAvailability::class, PendingStockAvailabilityResolver::class);
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
        Event::listen(CommandStarting::class, function (CommandStarting $event): void {
            $this->app->make(DatabaseCommandGuard::class)->assertCommandAllowed($event->command);
        });

        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(Order::class, OrderPolicy::class);
        Gate::policy(Document::class, DocumentPolicy::class);
        Gate::policy(Message::class, MessagePolicy::class);
        Gate::policy(MessageAttachment::class, MessageAttachmentPolicy::class);
        Gate::define('manage-users', fn (User $user): bool => $user->isAdministrator());
        Gate::define('manage-notifications', fn (User $user): bool => $user->isAdministrator()
            && $user->organization->code === OrganizationCode::AUTOKLUBY);

        View::composer('layouts.app', function ($view): void {
            $user = auth()->user();
            $view->with('globalUnreadMessages', $user === null
                ? 0
                : $this->app->make(DiscussionService::class)->globalUnreadCount($user));
        });

        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)->by(strtolower((string) $request->input('email')).'|'.$request->ip()));
        RateLimiter::for('pohoda-bridge', fn (Request $request) => Limit::perMinute(
            max(1, (int) config('integrations.pohoda_bridge.rate_limit_per_minute', 60)),
        )->by((string) $request->ip()));
    }
}
