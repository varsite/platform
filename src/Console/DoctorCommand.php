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
use Varsite\Platform\Support\ModuleRegistry;

/**
 * `varsite:doctor` — pełna diagnostyka instalacji/wdrożenia. Odpowiada na
 * "białą stronę" faktami zamiast zgadywania: środowisko PHP, klucz aplikacji,
 * uprawnienia, integralność pakietów vendor (w tym MARTWE SYMLINKI po deployu
 * FTP z path-repo), baza, panel, cache i spójność routingu. Kod wyjścia 1 przy
 * problemach krytycznych — nadaje się do CI i skryptów wdrożeniowych.
 */
final class DoctorCommand extends Command
{
    protected $signature = 'varsite:doctor {--fix : Napraw automatycznie to, co da się naprawić bezpiecznie}';

    protected $description = 'Diagnostyka instalacji platformy: środowisko, pakiety, baza, panel, routing (kod 1 przy błędach krytycznych)';

    private const REQUIRED_PHP = '8.3.0';

    private const REQUIRED_EXTENSIONS = [
        'ctype', 'curl', 'dom', 'fileinfo', 'json', 'mbstring', 'openssl', 'pdo', 'tokenizer',
    ];

    /** @var list<array{0:'PASS'|'WARN'|'FAIL',1:string,2:string}> */
    private array $results = [];

    /** Czy baza odpowiada — kontrole schematu wykonujemy wyłącznie wtedy. */
    private bool $databaseReachable = false;

    public function handle(ModuleManager $modules, RouteRegistry $registry, Router $router): int
    {
        $this->components->info('Varsite Platform — diagnostyka (doctor)');

        // Diagnostyka uruchamiana jest wtedy, gdy coś nie działa — sama nie może
        // przerwać się wyjątkiem. Każda grupa kontroli jest izolowana.
        $this->guard('Środowisko', fn () => $this->checkEnvironment());
        $this->guard('Aplikacja', fn () => $this->checkApplication());
        $this->guard('Pakiety', fn () => $this->checkVendorIntegrity());
        $this->guard('Baza danych', fn () => $this->checkDatabase());
        $this->guard('Migracje', fn () => $this->checkMigrations());
        $this->guard('Uwierzytelnianie', fn () => $this->checkAuth());
        $this->guard('Panel', fn () => $this->checkPanel());
        $this->guard('Routing', fn () => $this->checkRouting($registry, $router, $modules));
        $this->guard('Moduły', fn () => $this->checkModules($modules));
        $this->guard('Inwentarz modułów', fn () => $this->checkModuleInventory());
        $this->guard('Infrastruktura', fn () => $this->checkInfrastructure());

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

        $url = (string) config('app.url');
        if ($url === '' || $url === 'http://localhost') {
            $this->caution(
                'APP_URL nieustawiony (\''.$url.'\')',
                'Ustaw APP_URL w .env na rzeczywisty adres — używany w linkach, e-mailach i zasobach panelu.',
            );
        } else {
            $this->ok('APP_URL: '.$url);
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
            $this->databaseReachable = true;
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

        if ($violations === []) {
            $this->ok('Integralność routingu (audyt własności)');

            return;
        }

        // Przy aktywnym route:cache router zna trasy z pliku cache, a rejestr —
        // z bieżącego kodu. Rozbieżność po aktualizacji pakietów oznacza po prostu
        // nieaktualny cache, a nie naruszenie architektury.
        if ($this->laravel->routesAreCached()) {
            $this->problem(
                sprintf('Cache tras jest nieaktualny (%d tras z poprzedniej wersji)', count($violations)),
                'Uruchom: php artisan optimize:clear && php artisan route:cache — następnie ponownie php artisan varsite:doctor',
            );

            return;
        }

        $this->problem(
            'Trasy poza registrarem: '.implode('; ', $violations),
            'Wszystkie trasy /api/* muszą przechodzić przez ModuleRouteRegistrar.',
        );
    }

    /** Migracje: brak zaległych to warunek spójności schematu z kodem. */
    private function checkMigrations(): void
    {
        if (! $this->databaseReachable) {
            $this->caution('Migracje — pominięto (brak połączenia z bazą)', 'Napraw połączenie z bazą, wtedy diagnostyka sprawdzi schemat.');

            return;
        }

        if (! Schema::hasTable('migrations')) {
            $this->problem('Brak tabeli migracji', 'Uruchom: php artisan varsite:install (albo php artisan migrate --force).');

            return;
        }

        try {
            $pending = $this->laravel->make('migrator')->getMigrationFiles(
                $this->laravel->make('migrator')->paths() + [$this->laravel->databasePath('migrations')],
            );
            $ran = $this->laravel->make('migrator')->getRepository()->getRan();
            $waiting = array_diff(array_keys($pending), $ran);

            $waiting === []
                ? $this->ok('Migracje wykonane ('.count($ran).')')
                : $this->problem(
                    'Zaległe migracje: '.count($waiting),
                    'Uruchom: php artisan varsite:update (albo php artisan migrate --force).',
                );
        } catch (Throwable $e) {
            $this->caution('Nie udało się sprawdzić migracji: '.$e->getMessage(), 'Zweryfikuj ręcznie: php artisan migrate:status');
        }
    }

    /** Uwierzytelnianie: klucz, Sanctum i model użytkownika muszą być spójne. */
    private function checkAuth(): void
    {
        class_exists(\Laravel\Sanctum\Sanctum::class)
            ? $this->ok('Sanctum dostępny')
            : $this->problem('Brak pakietu Sanctum', 'Uruchom: composer require laravel/sanctum && php artisan varsite:install');

        $model = (string) config('auth.providers.users.model', '');

        if ($model === '' || ! class_exists($model)) {
            $this->problem('Model użytkownika nieustawiony ('.($model ?: 'brak').')', 'Sprawdź config/auth.php → providers.users.model.');

            return;
        }

        in_array(\Laravel\Sanctum\HasApiTokens::class, class_uses_recursive($model), true)
            ? $this->ok('Model użytkownika z obsługą tokenów API')
            : $this->problem(
                $model.' bez traitu HasApiTokens',
                'Dodaj trait Laravel\\Sanctum\\HasApiTokens w modelu albo uruchom: php artisan varsite:install',
            );

        if ($this->databaseReachable && Schema::hasTable('users')) {
            Schema::hasColumn('users', 'role')
                ? $this->ok('Kolumna role w tabeli users')
                : $this->caution('Brak kolumny "role" w users', 'Uruchom: php artisan varsite:update (migracja rdzenia).');
        }
    }

    /** Moduły: konfiguracja, migracje i unikalność uprawnień. */
    private function checkModules(ModuleManager $modules): void
    {
        $all = $modules->all();

        if ($all === []) {
            $this->caution('Brak zainstalowanych modułów', 'Dodaj moduły: composer require varsite/<nazwa> && php artisan varsite:module install <nazwa>');

            return;
        }

        $seen = [];
        $collisions = [];

        foreach ($all as $module) {
            foreach ($module->permissions as $permission) {
                if (isset($seen[$permission]) && $seen[$permission] !== $module->key) {
                    $collisions[] = sprintf('%s (%s ↔ %s)', $permission, $seen[$permission], $module->key);
                }
                $seen[$permission] = $module->key;
            }
        }

        $collisions === []
            ? $this->ok(sprintf('Uprawnienia modułów bez kolizji (%d)', count($seen)))
            : $this->problem('Kolizje uprawnień: '.implode(', ', $collisions), 'Uprawnienia muszą mieć prefiks modułu (np. "blog.view").');
    }

    /**
     * Inwentarz modułów: każdy wykryty moduł musi mieć rekord stanu, a zapisana
     * wersja zgadzać się z tą, którą deklaruje kod.
     */
    private function checkModuleInventory(): void
    {
        if (! $this->databaseReachable || ! Schema::hasTable('platform_modules')) {
            $this->caution('Inwentarz modułów niedostępny', 'Uruchom: php artisan varsite:update');

            return;
        }

        /** @var ModuleRegistry $registry */
        $registry = $this->laravel->make(ModuleRegistry::class);
        /** @var ModuleManager $manager */
        $manager = $this->laravel->make(ModuleManager::class);

        $state = $registry->all();
        $missing = [];
        $outdated = [];

        foreach ($manager->discovered() as $manifest) {
            $row = $state[$manifest->key] ?? null;

            if ($row === null) {
                $missing[] = $manifest->key;

                continue;
            }

            if ((string) $row->installed_version !== $manifest->version) {
                $outdated[] = sprintf('%s (%s → %s)', $manifest->key, $row->installed_version, $manifest->version);
            }
        }

        if ($missing !== []) {
            $this->repairable(
                'Moduły bez rekordu w inwentarzu: '.implode(', ', $missing),
                'Uruchom: php artisan varsite:update',
                static function () use ($registry): bool {
                    $registry->synchronize((string) config('platform.contract.version'));

                    return true;
                },
            );
        } elseif ($outdated !== []) {
            $this->repairable(
                'Zapisana wersja różni się od kodu: '.implode(', ', $outdated),
                'Uruchom: php artisan varsite:update',
                static function () use ($registry): bool {
                    $registry->synchronize((string) config('platform.contract.version'));

                    return true;
                },
            );
        } else {
            $this->ok(sprintf('Inwentarz modułów spójny (%d)', count($state)));
        }

        $failures = $manager->failures();

        if ($failures !== []) {
            foreach ($failures as $key => $message) {
                $this->problem(
                    sprintf('Moduł "%s" nie zarejestrował się poprawnie', $key),
                    $message.' — sprawdź logi i zgodność wersji pakietu.',
                );
            }
        }

        $disabled = array_filter($state, static fn (object $row): bool => (string) $row->status === 'disabled');

        if ($disabled !== []) {
            $this->caution(
                'Moduły wyłączone: '.implode(', ', array_keys($disabled)),
                'To świadoma decyzja administratora — włącz je na ekranie Moduły, jeśli mają działać.',
            );
        }
    }

    /** Infrastruktura: kolejki, harmonogram, poczta, cache. */
    private function checkInfrastructure(): void
    {
        $queue = (string) config('queue.default');
        if ($queue === 'sync') {
            $this->caution(
                'Kolejki w trybie synchronicznym',
                'Do zadań w tle ustaw QUEUE_CONNECTION=database (lub redis) i uruchom workera: php artisan queue:work',
            );
        } else {
            $this->ok('Kolejki: '.$queue);
            if ($queue === 'database' && $this->databaseReachable && ! Schema::hasTable('jobs')) {
                $this->problem('Brak tabeli jobs dla kolejki database', 'Uruchom: php artisan queue:table && php artisan migrate --force');
            }
        }

        $schedule = $this->laravel->make(\Illuminate\Console\Scheduling\Schedule::class);
        $events = method_exists($schedule, 'events') ? $schedule->events() : [];

        if ($events === []) {
            $this->ok('Harmonogram: brak zadań (cron niewymagany)');
        } else {
            $this->caution(
                sprintf('Harmonogram: %d zadań — wymaga wpisu cron', count($events)),
                'Dodaj w cron: * * * * * cd '.base_path().' && php artisan schedule:run >> /dev/null 2>&1',
            );
        }

        $mailer = (string) config('mail.default');
        if (in_array($mailer, ['log', 'array'], true)) {
            $this->caution(
                'Poczta w trybie "'.$mailer.'" — wiadomości nie są wysyłane',
                'Skonfiguruj MAIL_MAILER i dane serwera SMTP w .env.',
            );
        } else {
            $this->ok('Poczta: '.$mailer);
        }

        $cache = (string) config('cache.default');
        $this->ok('Cache: '.$cache);
        if ($cache === 'database' && $this->databaseReachable && ! Schema::hasTable('cache')) {
            $this->problem('Brak tabeli cache', 'Uruchom: php artisan cache:table && php artisan migrate --force');
        }

        if ($this->laravel->environment('production')) {
            $this->laravel->configurationIsCached()
                ? $this->ok('Cache konfiguracji zbudowany')
                : $this->caution('Konfiguracja bez cache w produkcji', 'Uruchom: php artisan varsite:update (buduje config:cache i route:cache).');
        }
    }

    /** Izolacja grupy kontroli — awaria jednej nie przerywa całej diagnostyki. */
    private function guard(string $group, callable $check): void
    {
        try {
            $check();
        } catch (Throwable $e) {
            $this->problem(
                sprintf('%s — diagnostyka nie mogła dokończyć sprawdzenia', $group),
                sprintf('%s: %s', $e::class, $e->getMessage()),
            );
        }
    }

    /**
     * Problem możliwy do naprawienia automatycznie.
     *
     * Naprawiamy wyłącznie operacje BEZPIECZNE: takie, które nie usuwają danych
     * i nie zmieniają decyzji administratora. Migracje i konfiguracja środowiska
     * pozostają poza zakresem --fix.
     *
     * @param callable():bool $repair
     */
    private function repairable(string $label, string $remedy, callable $repair): void
    {
        if (! $this->option('fix')) {
            $this->problem($label, $remedy.' (lub uruchom: php artisan varsite:doctor --fix)');

            return;
        }

        if ($repair()) {
            $this->results[] = ['PASS', $label.' — naprawione automatycznie', ''];
            $this->line(sprintf('  <fg=green>NAPRAWIONO</> %s', $label));

            return;
        }

        $this->problem($label, $remedy);
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

        $fails = count(array_filter($this->results, static fn (array $r): bool => $r[0] === 'FAIL'));
        $warns = count(array_filter($this->results, static fn (array $r): bool => $r[0] === 'WARN'));
        $passes = count($this->results) - $fails - $warns;

        $this->newLine();

        if ($fails === 0) {
            $this->components->info(sprintf(
                'PLATFORMA JEST GOTOWA DO PRACY.   %d sprawdzeń OK%s',
                $passes,
                $warns > 0 ? sprintf(', %d zalecenie(a) do rozważenia', $warns) : '',
            ));

            return;
        }

        $this->components->error(sprintf(
            'PLATFORMA NIE JEST GOTOWA DO PRACY.   %d problem(ów) krytycznych do naprawienia',
            $fails,
        ));
        $this->newLine();
        $this->line('  <options=bold>Do naprawienia:</>');
        foreach ($this->results as [$status, $label, $remedy]) {
            if ($status === 'FAIL') {
                $this->line('   <fg=red>✗</> '.$label);
                if ($remedy !== '') {
                    $this->line('     <fg=gray>→ '.$remedy.'</>');
                }
            }
        }
    }

    private function relative(string $path): string
    {
        return str_replace(base_path().'/', '', $path);
    }
}
