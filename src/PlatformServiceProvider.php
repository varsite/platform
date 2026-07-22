<?php

declare(strict_types=1);

namespace Varsite\Platform;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Varsite\Platform\Console\AdminCreateCommand;
use Varsite\Platform\Console\DoctorCommand;
use Varsite\Platform\Console\InstallCommand;
use Varsite\Platform\Console\ModuleCommand;
use Varsite\Platform\Console\ModulesCommand;
use Varsite\Platform\Console\PlatformRoutesCommand;
use Varsite\Platform\Console\RoutesVerifyCommand;
use Varsite\Platform\Http\Controllers\FrontendController;
use Varsite\Platform\Routing\ModuleRouteRegistrar;
use Varsite\Platform\Routing\RouteRegistry;
use Varsite\Platform\Support\ModuleManager;
use Varsite\Platform\Support\NavRegistry;

/**
 * Provider rdzenia platformy (Core-first routing).
 * - Rejestry: moduły, nawigacja, trasy (własność + kolizje).
 * - Trasy Core przez ModuleRouteRegistrar (jak każdy moduł).
 * - Frontendy + fallback rejestrowane PO boot wszystkich providerów
 *   (fallback zawsze ostatni), następnie audyt spójności routingu.
 */
final class PlatformServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/platform.php', 'platform');

        $this->app->singleton(ModuleManager::class);
        $this->app->singleton(NavRegistry::class);
        $this->app->singleton(RouteRegistry::class);
        $this->app->singleton(
            ModuleRouteRegistrar::class,
            fn (Application $app): ModuleRouteRegistrar => new ModuleRouteRegistrar(
                $app->make(Router::class),
                $app->make(RouteRegistry::class),
            ),
        );
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $registrar = $this->app->make(ModuleRouteRegistrar::class);
        $registrar->register('core', require __DIR__.'/../routes/api.php');

        $this->registerCoreNavigation();

        $this->publishes([
            __DIR__.'/../config/platform.php' => config_path('platform.php'),
        ], 'platform-config');

        // Zbudowany panel podróżuje w pakiecie (model Filament) — publikacja do public/admin.
        $this->publishes([
            __DIR__.'/../resources/dist/admin' => public_path('admin'),
        ], 'platform-admin-assets');

        if ($this->app->runningInConsole()) {
            $this->commands([AdminCreateCommand::class, DoctorCommand::class, InstallCommand::class, ModuleCommand::class, PlatformRoutesCommand::class, RoutesVerifyCommand::class]);
        }

        $this->app->booted(function (): void {
            $this->registerFrontendRoutes();
            $this->auditRouting();
        });
    }

    private function registerCoreNavigation(): void
    {
        $nav = $this->app->make(NavRegistry::class);

        $nav->item('Przegląd', ['id' => 'dashboard', 'label' => 'Pulpit', 'icon' => 'layout-dashboard', 'href' => '/'], groupOrder: 10);
        $nav->item('System', ['id' => 'users', 'label' => 'Użytkownicy', 'icon' => 'users', 'href' => '/users', 'order' => 10], groupOrder: 90);
        $nav->item('System', ['id' => 'settings', 'label' => 'Ustawienia', 'icon' => 'settings', 'href' => '/settings', 'order' => 20], groupOrder: 90);
    }

    /** Frontendy i fallback — po wszystkich providerach, żeby fallback był ostatnią trasą. */
    private function registerFrontendRoutes(): void
    {
        if ($this->app->routesAreCached()) {
            return; // trasy frontendów są już w route:cache
        }

        Route::get('/', [FrontendController::class, 'website'])->name('platform.website');
        Route::get('admin/config.js', [FrontendController::class, 'adminConfig'])->name('platform.admin.config');
        Route::get('admin/assets/{path}', [FrontendController::class, 'adminAsset'])
            ->where('path', '.*')
            ->name('platform.admin.asset');
        Route::get('admin/{path?}', [FrontendController::class, 'admin'])
            ->where('path', '.*')
            ->name('platform.admin');
        Route::fallback([FrontendController::class, 'fallback']);
    }

    private function auditRouting(): void
    {
        $violations = $this->app->make(RouteRegistry::class)
            ->auditRouter($this->app->make(Router::class));

        if ($violations === []) {
            return;
        }

        $message = 'Integralność routingu naruszona: '.implode('; ', $violations);

        if ($this->app->environment('production')) {
            Log::critical($message);

            return;
        }

        throw new \RuntimeException($message.' — użyj ModuleRouteRegistrar (varsite:routes:verify).');
    }
}
