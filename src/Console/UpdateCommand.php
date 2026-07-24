<?php

declare(strict_types=1);

namespace Varsite\Platform\Console;

use Illuminate\Console\Command;
use Varsite\Platform\Support\ModuleManager;

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

    public function handle(ModuleManager $modules): int
    {
        $this->components->info('Varsite Platform — aktualizacja');

        $installed = $modules->all();
        $keys = $modules->ids();

        $this->components->twoColumnDetail(
            '<options=bold>Zainstalowane moduły</>',
            $keys === [] ? 'brak (sam rdzeń)' : implode(', ', array_map(
                static fn (object $m): string => $m->key().' '.$m->version(),
                $installed,
            )),
        );

        // Nowe wersje mogą wnosić nowe klucze konfiguracji — publikujemy bez --force,
        // żeby nie nadpisać ustawień wdrożenia.
        $this->components->task('Konfiguracja platformy (nowe klucze)', function (): bool {
            $this->callSilently('vendor:publish', ['--tag' => 'platform-config']);

            return true;
        });

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
        $healthy = $this->call('varsite:doctor') === self::SUCCESS;
        $this->newLine();

        if (! $healthy) {
            $this->components->error('Aktualizacja wymaga uwagi — powyżej wypisano problemy wraz z rozwiązaniem.');

            return self::FAILURE;
        }

        $this->components->info('Aktualizacja zakończona. Platforma działa poprawnie.');

        return self::SUCCESS;
    }
}
