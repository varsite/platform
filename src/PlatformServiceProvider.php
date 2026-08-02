<?php

declare(strict_types=1);

namespace Varsite\Platform;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Varsite\Platform\Console\AdminCreateCommand;
use Varsite\Platform\Console\DoctorCommand;
use Varsite\Platform\Console\InstallCommand;
use Varsite\Platform\Console\ModuleCommand;
use Varsite\Platform\Console\UpdateCommand;
use Varsite\Platform\Console\ModulesCommand;
use Varsite\Platform\Console\PlatformRoutesCommand;
use Varsite\Platform\Console\RoutesVerifyCommand;
use Varsite\Platform\Http\Controllers\FrontendController;
use Varsite\Platform\Capabilities\CapabilityRegistry;
use Throwable;
use Varsite\Platform\Capabilities\Action;
use Varsite\Platform\Capabilities\Column;
use Varsite\Platform\Capabilities\Field;
use Varsite\Platform\Capabilities\Filter;
use Varsite\Platform\Capabilities\ResourceCapability;
use Varsite\Platform\Capabilities\SettingCapability;
use Varsite\Platform\Capabilities\WidgetCapability;
use Varsite\Platform\Enums\ModuleStatus;
use Varsite\Platform\Routing\ModuleRouteRegistrar;
use Varsite\Platform\Routing\RouteRegistry;
use Varsite\Platform\Support\InstallationState;
use Varsite\Platform\Support\ModuleManager;
use Varsite\Platform\Support\ModuleRegistry;
use Varsite\Platform\Support\Rbac;
use Varsite\Platform\Support\Settings;

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
        $this->app->singleton(CapabilityRegistry::class);
        $this->app->singleton(Rbac::class);
        $this->app->singleton(Settings::class);
        $this->app->singleton(ModuleRegistry::class);
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

        // Rozstrzyganie uprawnień należy do Core — moduły deklarują wyłącznie
        // identyfikatory i polityki, nigdy nie wiedzą o rolach ani użytkownikach.
        Gate::before(function ($user, string $ability): ?bool {
            return $this->app->make(Rbac::class)->allows($user, $ability) ? true : null;
        });


        // Stan platformy jako zwykła możliwość — ten sam mechanizm, z którego
        // korzystają moduły. Renderer decyduje, gdzie i jak ją pokazać.
        $this->applyStoredSettings();

        $this->app->make(CapabilityRegistry::class)->register(
            ResourceCapability::make('platform.modules')
                ->label('Moduł', 'Moduły')
                ->icon('box')
                ->endpoint('/v1/admin/modules')
                ->permission('platform.modules')
                ->columns([
                    Column::text('name')->label('Moduł')->sortable()->primary(),
                    Column::status('status', ModuleStatus::tones())->label('Stan'),
                    Column::text('version')->label('Wersja'),
                    Column::text('author')->label('Autor'),
                    Column::number('boot_time_ms')->label('Boot (ms)'),
                ])
                ->filters([
                    Filter::search(['name', 'key']),
                    Filter::segmented('status', ['all' => 'Wszystkie'] + ModuleStatus::options()),
                ])
                ->actions([]),
        )->register(
            ResourceCapability::make('platform.users')
                ->label('Konto', 'Użytkownicy')
                ->icon('users')
                ->endpoint('/v1/admin/users')
                ->permission('platform.users')
                ->columns([
                    Column::text('name')->label('Imię i nazwisko')->sortable()->primary(),
                    Column::text('email')->label('E-mail'),
                    Column::badge('role')->label('Rola'),
                    Column::date('created_at')->label('Dodano')->sortable(),
                ])
                ->filters([
                    Filter::search(['name', 'email']),
                    Filter::select('role', '/v1/admin/users/roles')->label('Rola'),
                ])
                ->form([
                    Field::text('name')->label('Imię i nazwisko')->required(),
                    Field::text('email')->label('E-mail')->required(),
                    Field::reference('role', '/v1/admin/users/roles')->label('Rola')
                        ->hint('Decyduje o zakresie uprawnień w panelu.'),
                    Field::text('password')->label('Hasło')
                        ->hint('Przy edycji zostaw puste, aby nie zmieniać.'),
                ])
                ->actions([Action::edit(), Action::delete()->permission('platform.users')]),
        )->register(
            SettingCapability::make('platform.general')
                ->label('Ustawienia ogólne')
                ->description('Nazwa i adres instalacji oraz strefa czasowa używana w interfejsie.')
                ->icon('settings')
                ->permission('platform.settings')
                ->order(10)
                ->fields([
                    Field::text('name')->label('Nazwa instalacji')->required()
                        ->hint('Widoczna w panelu i tytułach stron.'),
                    Field::text('url')->label('Adres aplikacji')
                        ->hint('Używany w linkach i wiadomościach e-mail.'),
                    Field::text('timezone')->label('Strefa czasowa')
                        ->hint('Identyfikator IANA, np. Europe/Warsaw.'),
                ])
                ->defaults([
                    'name' => config('app.name'),
                    'url' => config('app.url'),
                    'timezone' => config('app.timezone'),
                ])
                ->rules([
                    'name' => ['required', 'string', 'max:120'],
                    'url' => ['nullable', 'url', 'max:190'],
                    'timezone' => ['nullable', 'timezone'],
                ])
                ->appliesTo([
                    'name' => 'app.name',
                    'url' => 'app.url',
                    'timezone' => 'app.timezone',
                ]),
        )->register(
            WidgetCapability::make('platform.health')
                ->label('Stan platformy')
                ->icon('activity')
                ->variant('status')
                ->size(WidgetCapability::SIZE_HALF)
                ->order(1)
                ->endpoint('/v1/admin/health-summary')
                ->refresh(120),
        );

        $this->publishes([
            __DIR__.'/../config/platform.php' => config_path('platform.php'),
        ], 'platform-config');

        // Zbudowany panel podróżuje w pakiecie (model Filament) — publikacja do public/admin.
        $this->publishes([
            __DIR__.'/../resources/dist/admin' => public_path('admin'),
        ], 'platform-admin-assets');

        if ($this->app->runningInConsole()) {
            $this->commands([AdminCreateCommand::class, DoctorCommand::class, InstallCommand::class, ModuleCommand::class, PlatformRoutesCommand::class, RoutesVerifyCommand::class, UpdateCommand::class]);
        }

        $this->app->booted(function (): void {
            $this->registerFrontendRoutes();
            $this->auditRouting();
        });
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

    /**
     * Zapisane ustawienia nadpisują konfigurację aplikacji — JEDEN centralny
     * mechanizm dla wszystkich grup, obecnych i przyszłych.
     *
     * Moduł deklaruje mapowanie przez `SettingCapability::appliesTo()` i nie
     * pisze żadnej logiki ładowania. W rdzeniu nie ma listy modułów ani
     * wyjątków — iterujemy po rejestrze możliwości (N4).
     *
     * Odczyt jest tani (jeden wiersz z cache na grupę) i pomijany, gdy baza
     * jeszcze nie istnieje albo trwa instalacja czy budowanie cache.
     */
    private function applyStoredSettings(): void
    {
        if ($this->app->runningInConsole() && ! $this->app->environment('testing')) {
            $command = $_SERVER['argv'][1] ?? '';

            if (in_array($command, ['varsite:install', 'migrate', 'config:cache', 'optimize'], true)) {
                return;
            }
        }

        try {
            if (! InstallationState::hasTable('platform_settings')) {
                return;
            }

            $settings = $this->app->make(Settings::class);
            $registry = $this->app->make(CapabilityRegistry::class);
        } catch (Throwable) {
            return; // brak bazy lub tabeli — konfiguracja pozostaje bez zmian
        }

        foreach ($registry->all() as $capability) {
            if (! $capability instanceof SettingCapability) {
                continue;
            }

            $map = $capability->configMap();

            if ($map === []) {
                continue; // grupa bez wpływu na konfigurację — sama dana
            }

            $values = $settings->all($capability->key());

            foreach ($map as $field => $target) {
                $value = $values[$field] ?? null;

                if ($value !== null && $value !== '') {
                    config([$target => $value]);
                }
            }
        }
    }
}
