<?php

declare(strict_types=1);

namespace Varsite\Platform\Console;

use Composer\InstalledVersions;
use Illuminate\Console\Command;
use Varsite\Platform\Contracts\PlatformModule;
use Varsite\Platform\Routing\RouteRegistry;
use Varsite\Platform\Support\ModuleManager;

/**
 * Zarządzanie modułami platformy — jedna komenda, akcje jak w menedżerze pakietów:
 *
 *   varsite:module list
 *   varsite:module install <key>   (po: composer require varsite/module-<key>)
 *
 * "install" jest idempotentne: publikuje zasoby modułu (tag varsite-module-<key>),
 * uruchamia jego migracje i raportuje wszystko, co moduł wniósł do platformy.
 * Odinstalowanie = migrate:rollback --path=vendor/varsite/module-<key>/database/migrations
 * → composer remove (provider znika z Package Discovery; trasy/nav/uprawnienia razem z nim).
 */
final class ModuleCommand extends Command
{
    protected $signature = 'varsite:module
        {action : list | install}
        {module? : Klucz modułu przy "install", np. audio}
        {--skip-migrations : Nie uruchamiaj migracji modułu}';

    protected $description = 'Moduły platformy: lista i instalacja (varsite:module install <key>)';

    public function handle(ModuleManager $modules, RouteRegistry $routes): int
    {
        return match ($this->argument('action')) {
            'list' => $this->listModules($modules),
            'install' => $this->installModule($modules, $routes),
            default => $this->unknownAction(),
        };
    }

    private function listModules(ModuleManager $modules): int
    {
        $rows = array_map(
            static fn (PlatformModule $m): array => [$m->key(), $m->version(), implode(', ', $m->permissions())],
            $modules->all(),
        );

        if ($rows === []) {
            $this->components->warn('Brak zainstalowanych modułów — sam rdzeń platformy.');

            return self::SUCCESS;
        }

        $this->table(['Moduł', 'Wersja', 'Uprawnienia'], $rows);

        return self::SUCCESS;
    }

    private function installModule(ModuleManager $modules, RouteRegistry $routes): int
    {
        $key = (string) $this->argument('module');

        if ($key === '') {
            $this->components->error('Podaj klucz modułu: varsite:module install <key>');

            return self::INVALID;
        }

        $package = "varsite/module-{$key}";
        $module = $modules->get($key);

        if ($module === null) {
            $this->components->error(sprintf(
                'Moduł "%s" nie jest zarejestrowany. Najpierw: composer require %s',
                $key,
                $package,
            ));

            return self::FAILURE;
        }

        $this->components->info(sprintf('Varsite Platform — moduł "%s" (%s)', $key, $module->version()));

        $this->components->task('Zasoby modułu (config, tłumaczenia — jeśli publikowane)', function () use ($key): bool {
            $this->callSilently('vendor:publish', ['--tag' => "varsite-module-{$key}"]);

            return true;
        });

        if (! $this->option('skip-migrations')) {
            $this->components->task('Migracje modułu', function () use ($package): bool {
                $path = InstalledVersions::isInstalled($package)
                    ? InstalledVersions::getInstallPath($package)
                    : null;
                $migrations = $path !== null ? $path.'/database/migrations' : null;

                if ($migrations === null || ! is_dir($migrations)) {
                    return true; // moduł bez własnych tabel
                }

                $this->callSilently('migrate', ['--path' => $migrations, '--realpath' => true, '--force' => true]);

                return true;
            });
        }

        $owned = collect($routes->all())
            ->filter(fn (string $owner): bool => $owner === $key)
            ->keys()
            ->map(function (string $entry): array {
                [$method, $uri] = explode(' ', $entry, 2);

                return [$method, '/'.$uri];
            })
            ->values();

        $this->newLine();
        $this->components->twoColumnDetail('<options=bold>Uprawnienia</>', implode(', ', $module->permissions()));
        $this->components->twoColumnDetail('<options=bold>Trasy API</>', (string) $owned->count());
        $this->table(['Metoda', 'URI'], $owned->all());
        $this->components->bulletList([
            'Panel: pozycje modułu pojawią się w nawigacji po zalogowaniu (rejestr nawigacji).',
            'Weryfikacja routingu: <options=bold>php artisan varsite:routes:verify</>',
        ]);

        return self::SUCCESS;
    }

    private function unknownAction(): int
    {
        $this->components->error('Nieznana akcja. Dostępne: list, install <key>.');

        return self::INVALID;
    }
}
