<?php

declare(strict_types=1);

namespace Varsite\Platform\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Varsite\Platform\Support\ModuleManager;
use Varsite\Platform\Support\ModuleRegistry;

/**
 * Aktualizacja instalacji po pobraniu nowych wersji pakietów.
 *
 * Odpowiednik `varsite:install` dla działającej platformy: wykonuje wszystkie
 * kroki wymagane po podbiciu wersji, ale NIE pyta o konfigurację projektu
 * (nazwa, URL, baza, administrator są już ustalone). Idempotentna — wielokrotne
 * uruchomienie niczego nie psuje.
 *
 * Kończy się diagnostyką: aktualizacja jest ukończona dopiero wtedy, gdy
 * `varsite:doctor` potwierdza sprawność systemu.
 */
final class UpdateCommand extends Command
{
    protected $signature = 'varsite:update
        {--skip-migrations : Pomiń migracje (np. gdy uruchamiasz je osobno w oknie serwisowym)}';

    protected $description = 'Aktualizuje instalację platformy po pobraniu nowych wersji pakietów';

    public function handle(ModuleManager $modules, ModuleRegistry $registry): int
    {
        $this->components->info('Varsite Platform — aktualizacja');

        $installed = $modules->all();
        $keys = $modules->ids();

        $this->components->twoColumnDetail(
            '<options=bold>Zainstalowane moduły</>',
            $keys === [] ? 'brak (sam rdzeń)' : implode(', ', array_map(
                static fn (object $m): string => $m->key.' '.$m->version,
                $installed,
            )),
        );

        // Nowe wersje mogą wnosić nowe klucze konfiguracji — publikujemy bez --force,
        // żeby nie nadpisać ustawień wdrożenia.
        $this->components->task('Konfiguracja platformy (nowe klucze)', function (): bool {
            $this->callSilently('vendor:publish', ['--tag' => 'platform-config']);

            return true;
        });

        // Inwentarz musi znać każdy wykryty moduł, zanim cokolwiek dalej się
        // wydarzy — inaczej nowy pakiet byłby niewidoczny w panelu.
        $this->components->task('Synchronizacja inwentarza modułów', function () use ($registry, &$sync): bool {
            $sync = $registry->synchronize((string) config('platform.contract.version'));

            return true;
        });

        if ($sync['added'] !== []) {
            $this->components->twoColumnDetail('<options=bold>Nowe moduły</>', implode(', ', $sync['added']));
        }

        if ($sync['updated'] !== []) {
            $this->components->twoColumnDetail('<options=bold>Zaktualizowane</>', implode(', ', $sync['updated']));
        }

        if (! $this->option('skip-migrations')) {
            $this->components->task('Migracje rdzenia i modułów', function (): bool {
                $this->callSilently('migrate', ['--force' => true]);

                return true;
            });
        }

        $this->components->task(
            $keys === [] ? 'Zasoby modułów (brak modułów)' : sprintf('Zasoby modułów (%s)', implode(', ', $keys)),
            function () use ($keys): bool {
                foreach ($keys as $key) {
                    $this->callSilently('vendor:publish', ['--tag' => "varsite-module-{$key}", '--force' => true]);
                }

                return true;
            },
        );

        // Panel podróżuje w pakiecie, więc nowa wersja panelu przyszła razem
        // z composer update — wystarczy odświeżyć publikację statyk w produkcji.
        $this->components->task('Przebudowa cache i zasobów panelu', function (): bool {
            $this->callSilently('optimize:clear');

            if ($this->laravel->environment('production')) {
                $this->callSilently('vendor:publish', ['--tag' => 'platform-admin-assets', '--force' => true]);
                $this->callSilently('config:cache');
                $this->callSilently('route:cache');
            }

            return true;
        });

        $this->newLine();

        // Diagnostyka MUSI działać w świeżym procesie: bieżący wystartował przed
        // aktualizacją, więc trzyma w pamięci trasy i konfigurację z poprzedniej
        // wersji. Uruchomienie jej tutaj dawałoby fałszywe rozbieżności.
        $artisan = base_path('artisan');

        if (is_file($artisan) && ! $this->laravel->runningUnitTests()) {
            $result = Process::path(base_path())->timeout(120)->run([PHP_BINARY, $artisan, 'varsite:doctor']);
            $this->output->write($result->output());
            $healthy = $result->successful();
        } else {
            // Środowisko bez skryptu artisan (np. testy pakietu) — diagnostyka w tym procesie.
            $healthy = $this->call('varsite:doctor') === self::SUCCESS;
        }

        $this->newLine();

        if (! $healthy) {
            $this->components->error('Aktualizacja wymaga uwagi — powyżej wypisano problemy wraz z rozwiązaniem.');

            return self::FAILURE;
        }

        $this->components->info('Aktualizacja zakończona. Platforma działa poprawnie.');

        return self::SUCCESS;
    }
}
