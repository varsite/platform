<?php

declare(strict_types=1);

namespace Varsite\Platform\Console;

use Composer\InstalledVersions;
use Illuminate\Console\Command;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;
use Varsite\Platform\Routing\RouteRegistry;
use Varsite\Platform\Support\ModuleManager;

/**
 * `varsite:doctor` — pełna diagnostyka instalacji/wdrożenia. Odpowiada na
 * "białą stronę" faktami zamiast zgadywania: środowisko PHP, klucz aplikacji,
 * uprawnienia, integralność pakietów vendor (w tym MARTWE SYMLINKI po deployu
 * FTP z path-repo), baza, panel, cache i spójność routingu. Kod wyjścia 1 przy
 * problemach krytycznych — nadaje się do CI i skryptów wdrożeniowych.
 */
final class DoctorCommand extends Command
{
    protected $signature = 'varsite:doctor';

    protected $description = 'Diagnostyka instalacji platformy: środowisko, pakiety, baza, panel, routing (kod 1 przy błędach krytycznych)';

    private const REQUIRED_PHP = '8.3.0';

    private const REQUIRED_EXTENSIONS = [
        'ctype', 'curl', 'dom', 'fileinfo', 'json', 'mbstring', 'openssl', 'pdo', 'tokenizer',
    ];

    /** @var list<array{0:'PASS'|'WARN'|'FAIL',1:string,2:string}> */
    private array $results = [];

    public function handle(ModuleManager $modules, RouteRegistry $registry, Router $router): int
    {
        $this->components->info('Varsite Platform — diagnostyka (doctor)');

        $this->checkEnvironment();
        $this->checkApplication();
        $this->checkVendorIntegrity();
        $this->checkDatabase();
        $this->checkPanel();
        $this->checkRouting($registry, $router, $modules);

        $this->render();

        $failed = array_filter($this->results, static fn (array $r): bool => $r[0] === 'FAIL');

        return $failed === [] ? self::SUCCESS : self::FAILURE;
    }

    private function checkEnvironment(): void
    {
        version_compare(PHP_VERSION, self::REQUIRED_PHP, '>=')
            ? $this->ok('PHP '.PHP_VERSION)
            : $this->problem(
                'PHP '.PHP_VERSION.' (wymagane ≥ '.self::REQUIRED_PHP.')',
                'Przełącz wersję PHP dla domeny (panel hostingu → PHP selector). Za stary PHP = pusta biała strona: platform_check Composera przerywa KAŻDE żądanie zanim Laravel wystartuje.',
            );

        $missing = array_values(array_filter(
            self::REQUIRED_EXTENSIONS,
            static fn (string $ext): bool => ! extension_loaded($ext),
        ));

        $missing === []
            ? $this->ok('Rozszerzenia PHP ('.count(self::REQUIRED_EXTENSIONS).')')
            : $this->problem('Brak rozszerzeń PHP: '.implode(', ', $missing), 'Włącz je w panelu hostingu (PHP selector → rozszerzenia).');
    }

    private function checkApplication(): void
    {
        config('app.key')
            ? $this->ok('APP_KEY ustawiony')
            : $this->problem('Brak APP_KEY', 'Uruchom: php artisan key:generate --force');

        if ($this->laravel->environment('production') && (bool) config('app.debug')) {
            $this->caution('APP_DEBUG=true w produkcji', 'Wyłącz debug: ujawnia szczegóły błędów (ścieżki, zapytania, sekrety).');
        } else {
            $this->ok('APP_ENV='.$this->laravel->environment().', APP_DEBUG='.var_export((bool) config('app.debug'), true));
        }

        foreach ([storage_path(), $this->laravel->bootstrapPath('cache')] as $path) {
            is_writable($path)
                ? $this->ok('Zapis: '.$this->relative($path))
                : $this->problem('Brak zapisu: '.$this->relative($path), 'Ustaw uprawnienia zapisu dla użytkownika PHP (np. 755/775 z właściwym właścicielem).');
        }
    }

    /** Sedno "pustego vendor/varsite": martwe symlinki po kopiowaniu projektu budowanego na path-repo. */
    private function checkVendorIntegrity(): void
    {
        if ((InstalledVersions::getRootPackage()['name'] ?? '') === 'varsite/platform') {
            $this->ok('Tryb deweloperski pakietu (root = platform-core) — kontrola vendor/varsite pominięta');

            return;
        }

        $vendorVarsite = base_path('vendor/varsite');

        if (! is_dir($vendorVarsite)) {
            $this->problem(
                'Brak katalogu vendor/varsite',
                'Pakiety platformy nie są zainstalowane. Uruchom composer require varsite/platform (+ moduły) i composer install NA SERWERZE. Uwaga: monorepo z GitHuba NIE jest pakietem VCS — używaj repozytorium "path" na sklonowany framework albo Packagist.',
            );

            return;
        }

        $dangling = [];
        $packages = [];

        foreach (scandir($vendorVarsite) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $vendorVarsite.'/'.$entry;
            $packages[] = $entry;

            if (is_link($path) && realpath($path) === false) {
                $dangling[] = $entry;
            }
        }

        if ($packages === []) {
            $this->problem(
                'vendor/varsite jest PUSTY',
                'Composer nie zainstalował pakietów. Najczęstsze przyczyny: (1) monorepo dodane jako repozytorium VCS — composer widzi tylko główny composer.json, nie pakiety w packages/*; (2) deploy FTP pominął symlinki path-repo. Rozwiązanie: composer install na serwerze z repozytorium "path" do sklonowanego frameworka, albo lokalnie z {"options":{"symlink":false}} i wgranie pełnego vendor.',
            );
        } elseif ($dangling !== []) {
            $this->problem(
                'Martwe symlinki w vendor/varsite: '.implode(', ', $dangling),
                'Projekt zbudowano na path-repo (symlinki), a na serwer trafiły same dowiązania bez celu. Rozwiązanie: composer install na serwerze, albo lokalna instalacja z {"options":{"symlink":false}} (kopie zamiast symlinków) przed wgraniem.',
            );
        } else {
            $this->ok('Pakiety vendor/varsite ('.implode(', ', $packages).')');
        }

        foreach (['varsite/platform'] as $package) {
            InstalledVersions::isInstalled($package)
                ? $this->ok($package.' '.(InstalledVersions::getPrettyVersion($package) ?? ''))
                : $this->problem($package.' nieznany Composerowi', 'composer require '.$package);
        }
    }

    private function checkDatabase(): void
    {
        try {
            DB::connection()->getPdo();
            $this->ok('Połączenie z bazą ('.DB::connection()->getDriverName().')');
        } catch (Throwable $e) {
            $this->problem('Baza danych: '.$e->getMessage(), 'Sprawdź DB_* w .env; następnie php artisan varsite:install (migracje).');

            return;
        }

        foreach (['users', 'personal_access_tokens'] as $table) {
            Schema::hasTable($table)
                ? $this->ok('Tabela '.$table)
                : $this->problem('Brak tabeli '.$table, 'Uruchom: php artisan varsite:install (albo php artisan migrate --force).');
        }

        if (Schema::hasTable('users')) {
            $count = (int) DB::table('users')->count();
            $count > 0
                ? $this->ok('Użytkownicy: '.$count)
                : $this->caution('Brak użytkowników', 'Utwórz administratora: php artisan varsite:admin --email=... --password=...');
        }
    }

    private function checkPanel(): void
    {
        $packageDist = dirname(__DIR__, 2).'/resources/dist/admin/index.html';

        is_file($packageDist)
            ? $this->ok('Panel w pakiecie Core')
            : $this->problem('Brak dystrybucji panelu w pakiecie', 'Zaktualizuj pakiet: composer update varsite/platform (dystrybucja panelu jest częścią pakietu).');

        is_file(public_path('admin/index.html'))
            ? $this->ok('Panel opublikowany w public/admin (statyki przez serwer WWW)')
            : $this->caution('Panel nieopublikowany w public/admin', 'Opcjonalna optymalizacja produkcyjna: php artisan vendor:publish --tag=platform-admin-assets --force (deep-linki i tak serwuje Core).');
    }

    private function checkRouting(RouteRegistry $registry, Router $router, ModuleManager $modules): void
    {
        $this->ok('Moduły: '.($modules->ids() === [] ? '(brak — sam rdzeń)' : implode(', ', $modules->ids())));
        $this->ok('route:cache '.($this->laravel->routesAreCached() ? 'AKTYWNY' : 'brak (dev)'));

        $count = count($registry->all());
        $count > 0
            ? $this->ok('Rejestr tras platformy: '.$count)
            : $this->problem('Rejestr tras pusty', 'Provider platformy nie wystartował — sprawdź sekcję pakietów powyżej.');

        $violations = $registry->auditRouter($router);
        $violations === []
            ? $this->ok('Integralność routingu (audyt własności)')
            : $this->problem('Trasy poza registrarem: '.implode('; ', $violations), 'Wszystkie trasy /api/* muszą przechodzić przez ModuleRouteRegistrar.');
    }

    private function ok(string $label): void
    {
        $this->results[] = ['PASS', $label, ''];
    }

    private function caution(string $label, string $remedy): void
    {
        $this->results[] = ['WARN', $label, $remedy];
    }

    private function problem(string $label, string $remedy): void
    {
        $this->results[] = ['FAIL', $label, $remedy];
    }

    private function render(): void
    {
        foreach ($this->results as [$status, $label, $remedy]) {
            $badge = match ($status) {
                'PASS' => '<fg=green>PASS</>',
                'WARN' => '<fg=yellow>WARN</>',
                'FAIL' => '<fg=red;options=bold>FAIL</>',
            };
            $this->components->twoColumnDetail($badge.'  '.$label, '');

            if ($remedy !== '') {
                $this->line('        <fg=gray>↳ '.$remedy.'</>');
            }
        }

        $this->newLine();
        $fails = count(array_filter($this->results, fn ($r) => $r[0] === 'FAIL'));
        $warns = count(array_filter($this->results, fn ($r) => $r[0] === 'WARN'));
        $this->components->info(sprintf('Wynik: %d PASS · %d WARN · %d FAIL', count($this->results) - $fails - $warns, $warns, $fails));
    }

    private function relative(string $path): string
    {
        return str_replace(base_path().'/', '', $path);
    }
}
