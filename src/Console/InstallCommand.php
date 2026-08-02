<?php

declare(strict_types=1);

namespace Varsite\Platform\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;
use Varsite\Platform\Support\InstallationState;
use Varsite\Platform\Support\ModuleManager;
use Varsite\Platform\Support\ModuleRegistry;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\text;

/**
 * Instalator platformy — JEDNA komenda od zera do działającego systemu
 * (doświadczenie jak Filament/Nova). Interaktywnie prowadzi przez nazwę projektu,
 * bazę danych, administratora i wybór modułów; następnie wykonuje pełny proces:
 * konfiguracja, Sanctum, model User, storage, migracje, zasoby modułów i cache.
 * Idempotentny — bezpieczny także przy aktualizacjach.
 *
 * Tryb nieinteraktywny (CI/provisioning): --no-interaction pomija pytania i używa
 * bieżącego .env; dane administratora z opcji lub ADMIN_EMAIL/ADMIN_PASSWORD.
 */
final class InstallCommand extends Command
{
    protected $signature = 'varsite:install
        {--admin-name= : Nazwa pierwszego administratora}
        {--admin-email= : E-mail pierwszego administratora}
        {--admin-password= : Hasło pierwszego administratora}
        {--no-admin : Pomiń tworzenie administratora}
        {--skip-migrations : Nie uruchamiaj migracji (np. build artefaktu bez bazy)}';

    protected $description = 'Instaluje/aktualizuje Varsite Platform w aplikacji Laravel (pełny proces jedną komendą)';

    public function handle(ModuleManager $modules): int
    {
        $this->components->info('Varsite Platform — instalacja');

        if ($this->input->isInteractive()) {
            $this->configureProject();

            if (! $this->configureDatabase()) {
                return self::FAILURE;
            }
        }

        $this->components->task('Konfiguracja platformy (config/platform.php)', function (): bool {
            $this->callSilently('vendor:publish', ['--tag' => 'platform-config']);

            return true;
        });

        $this->components->task('Sanctum (konfiguracja + migracja tokenów)', function (): bool {
            // publishesMigrations() nadaje świeży timestamp przy KAŻDEJ publikacji —
            // ponowny install tworzyłby duplikat. Publikujemy tylko gdy migracji nie ma
            // i tabela jeszcze nie istnieje (bezpieczne przy aktualizacjach).
            $hasMigration = glob(database_path('migrations/*_create_personal_access_tokens_table.php')) !== [];
            $hasTable = \Illuminate\Support\Facades\Schema::hasTable('personal_access_tokens');

            if (! $hasMigration && ! $hasTable) {
                $this->callSilently('vendor:publish', ['--provider' => 'Laravel\\Sanctum\\SanctumServiceProvider']);
            }

            return true;
        });

        $this->components->task('Model User (HasApiTokens + pole role)', fn (): bool => $this->ensureUserModel());

        $this->components->task('Storage (dowiązanie public/storage)', function (): bool {
            try {
                $this->callSilently('storage:link');
            } catch (\Throwable) {
                // dowiązanie już istnieje / środowisko bez symlinków — nie blokuje instalacji
            }

            return true;
        });

        if (! $this->option('skip-migrations')) {
            $this->components->task('Migracje bazy danych (rdzeń + moduły)', function (): bool {
                $this->callSilently('migrate', ['--force' => true]);
                InstallationState::forget();

                return true;
            });
        }

        // Inwentarz musi znać każdy moduł od razu po instalacji — także wtedy,
        // gdy migracje pominięto, bo panel bez rekordów pokazałby platformę
        // bez modułów aż do pierwszej aktualizacji.
        $this->components->task('Inwentarz modułów', function (): bool {
            $this->laravel->make(ModuleRegistry::class)
                ->synchronize((string) config('platform.contract.version'));

            return true;
        });

        $this->installOptionalModules();

        $this->createAdministrator();

        $this->components->task(
            $this->laravel->environment('production')
                ? 'Cache produkcyjny (config, routing) + publikacja assets panelu'
                : 'Czyszczenie cache (środowisko deweloperskie)',
            function (): bool {
                $this->callSilently('optimize:clear');

                if ($this->laravel->environment('production')) {
                    $this->callSilently('config:cache');
                    $this->callSilently('route:cache');
                    $this->callSilently('vendor:publish', ['--tag' => 'platform-admin-assets', '--force' => true]);
                }

                return true;
            },
        );

        // Weryfikacja końcowa: instalacja jest ukończona dopiero wtedy, gdy
        // diagnostyka potwierdza gotowość — nie wtedy, gdy komendy się wykonały.
        $this->newLine();
        $healthy = $this->call('varsite:doctor') === self::SUCCESS;

        $this->newLine();

        if (! $healthy) {
            $this->components->error('Instalacja wymaga uwagi — powyżej wypisano problemy wraz z rozwiązaniem.');
            $this->components->bulletList([
                'Popraw wskazane punkty i uruchom ponownie: <options=bold>php artisan varsite:install</> (komenda jest idempotentna)',
            ]);

            return self::FAILURE;
        }

        $url = rtrim((string) config('app.url'), '/');

        $this->components->info('Platforma jest gotowa do pracy.');
        $this->components->bulletList(array_filter([
            sprintf('Panel administracyjny: <options=bold>%s/admin</>', $url),
            sprintf('API platformy: <options=bold>%s/api/v1</>', $url),
            $this->laravel->environment('production')
                ? null
                : 'Serwer deweloperski: <options=bold>php artisan serve</>',
            'Kolejne moduły: <options=bold>composer require varsite/<nazwa></> → <options=bold>php artisan varsite:module install <nazwa></>',
        ]));

        return self::SUCCESS;
    }

    /** Krok 1: nazwa projektu (APP_NAME) — pokazywana w panelu i tytułach. */
    private function configureProject(): void
    {
        $current = (string) config('app.name', 'Laravel');

        $name = text(
            label: 'Nazwa projektu',
            placeholder: 'np. Studio NF Records',
            default: $current === 'Laravel' ? '' : $current,
            hint: 'Wyświetlana w panelu administracyjnym i tytułach stron.',
        );

        if ($name !== '' && $name !== $current) {
            $this->setEnv(['APP_NAME' => $name]);
            config(['app.name' => $name]);
        }

        $currentUrl = (string) config('app.url', 'http://localhost');

        $url = text(
            label: 'Adres aplikacji (URL)',
            placeholder: 'https://twoja-domena.pl',
            default: $currentUrl === 'http://localhost' ? '' : $currentUrl,
            hint: 'Używany w linkach, e-mailach i zasobach panelu.',
        );

        if ($url !== '' && $url !== $currentUrl) {
            $url = rtrim($url, '/');
            $this->setEnv(['APP_URL' => $url]);
            config(['app.url' => $url]);

            // Produkcyjny adres HTTPS => środowisko produkcyjne (cache, publikacja assetów).
            if (str_starts_with($url, 'https://') && $this->laravel->environment('local')) {
                if (confirm('Wygląda na wdrożenie produkcyjne. Ustawić APP_ENV=production i wyłączyć debug?', default: true)) {
                    $this->setEnv(['APP_ENV' => 'production', 'APP_DEBUG' => 'false']);
                    $this->laravel->detectEnvironment(static fn (): string => 'production');
                    config(['app.debug' => false]);
                }
            }
        }
    }

    /**
     * Krok 2: baza danych. Sprawdza bieżące połączenie; przy błędzie pyta o dane,
     * zapisuje je do .env i weryfikuje ponownie. Zwraca false, jeśli po konfiguracji
     * połączenie nadal nie działa (nie ma sensu iść dalej do migracji).
     */
    private function configureDatabase(): bool
    {
        if ($this->databaseWorks()) {
            $this->components->info('Baza danych: połączenie działa ('.config('database.default').').');

            return true;
        }

        $this->components->warn('Nie udało się połączyć z bazą danych — skonfigurujmy ją teraz.');

        $connection = confirm('Używasz MySQL/MariaDB? (Nie = SQLite, plik lokalny)', default: true)
            ? 'mysql'
            : 'sqlite';

        if ($connection === 'sqlite') {
            $path = database_path('database.sqlite');

            if (! is_file($path)) {
                @touch($path);
            }

            $this->setEnv([
                'DB_CONNECTION' => 'sqlite',
                'DB_DATABASE' => $path,
                'DB_HOST' => null, 'DB_PORT' => null, 'DB_USERNAME' => null, 'DB_PASSWORD' => null,
            ]);

            $this->applyConnectionConfig('sqlite', ['database' => $path, 'foreign_key_constraints' => true]);
        } else {
            $host = text('Host bazy', default: (string) env('DB_HOST', '127.0.0.1'), required: true);
            $port = text('Port', default: (string) env('DB_PORT', '3306'), required: true);
            $database = text('Nazwa bazy', default: (string) env('DB_DATABASE', ''), required: true);
            $username = text('Użytkownik', default: (string) env('DB_USERNAME', ''), required: true);
            $password = text('Hasło (puste = brak)', default: (string) env('DB_PASSWORD', ''));

            $this->setEnv([
                'DB_CONNECTION' => 'mysql',
                'DB_HOST' => $host, 'DB_PORT' => $port, 'DB_DATABASE' => $database,
                'DB_USERNAME' => $username, 'DB_PASSWORD' => $password,
            ]);

            $this->applyConnectionConfig('mysql', [
                'host' => $host, 'port' => $port, 'database' => $database,
                'username' => $username, 'password' => $password,
            ]);
        }

        if (! $this->databaseWorks()) {
            // Baza może jeszcze nie istnieć — spróbujmy ją utworzyć zamiast odsyłać
            // użytkownika do panelu hostingu.
            if ($connection === 'mysql' && isset($host, $port, $database, $username)
                && $this->tryCreateDatabase($host, $port, $database, $username, $password ?? '')) {
                $this->components->info(sprintf('Utworzono bazę danych "%s".', $database));
                $this->refreshConnection($connection);
            }
        }

        if (! $this->databaseWorks()) {
            $this->components->error('Nadal brak połączenia z bazą. Sprawdź dane w .env i uruchom ponownie: php artisan varsite:install');

            return false;
        }

        $this->components->info('Baza danych skonfigurowana i połączona.');

        return true;
    }

    /** Krok 4: wybór modułów do publikacji zasobów (migracje modułów i tak wykona etap migracji). */
    private function installOptionalModules(): void
    {
        $available = $this->laravel->make(ModuleManager::class)->all();

        if ($available === []) {
            return;
        }

        $options = [];
        foreach ($available as $module) {
            $options[$module->key] = sprintf('%s (%s)', $module->key, $module->version);
        }

        $selected = $this->input->isInteractive()
            ? multiselect(
                label: 'Moduły do aktywacji',
                options: $options,
                default: array_keys($options),
                hint: 'Zasoby wybranych modułów zostaną opublikowane. Więcej: php artisan varsite:module install <key>',
            )
            : array_keys($options);

        $this->components->task(
            sprintf('Zasoby modułów (%s)', $selected === [] ? 'pominięto' : implode(', ', $selected)),
            function () use ($selected): bool {
                foreach ($selected as $key) {
                    $this->callSilently('vendor:publish', ['--tag' => "varsite-module-{$key}"]);
                }

                return true;
            },
        );
    }

    private function databaseWorks(): bool
    {
        try {
            DB::connection()->getPdo();

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Próba utworzenia bazy, gdy serwer odpowiada, ale baza nie istnieje.
     * Łączymy się bez wskazania bazy — jeśli użytkownik nie ma uprawnień
     * do CREATE DATABASE (typowe na hostingach współdzielonych), po prostu
     * zwracamy false i prosimy o utworzenie jej w panelu.
     */
    private function tryCreateDatabase(string $host, string $port, string $database, string $username, string $password): bool
    {
        if (! preg_match('/^[A-Za-z0-9_]+$/', $database)) {
            return false; // nazwa spoza bezpiecznego zestawu — nie budujemy z niej SQL
        }

        try {
            $pdo = new \PDO(
                sprintf('mysql:host=%s;port=%s', $host, $port),
                $username,
                $password,
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_TIMEOUT => 5],
            );
            $pdo->exec(sprintf(
                'CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
                $database,
            ));

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function refreshConnection(string $connection): void
    {
        DB::purge($connection);
    }

    /** Ustawia config połączenia wprost z podanych wartości i czyni je domyślnym (bez polegania na przeładowaniu .env). */
    private function applyConnectionConfig(string $connection, array $values): void
    {
        $config = $this->laravel->make('config');
        $config->set('database.default', $connection);
        $config->set(
            "database.connections.{$connection}",
            array_merge((array) $config->get("database.connections.{$connection}", []), $values),
        );

        DB::purge($connection);
        DB::setDefaultConnection($connection);
    }

    /** Zapis par klucz=wartość do .env (wartość null usuwa klucz). Tworzy .env z .env.example, jeśli brak. */
    private function setEnv(array $values): void
    {
        $path = base_path('.env');

        if (! is_file($path)) {
            $example = base_path('.env.example');
            if (is_file($example)) {
                copy($example, $path);
            } else {
                file_put_contents($path, '');
            }
        }

        $content = (string) file_get_contents($path);

        foreach ($values as $key => $value) {
            if ($value === null) {
                $content = (string) preg_replace('/^'.preg_quote($key, '/').'=.*$\n?/m', '', $content);

                continue;
            }

            $quoted = preg_match('/\s/', (string) $value) ? '"'.$value.'"' : (string) $value;
            $line = $key.'='.$quoted;

            if (preg_match('/^'.preg_quote($key, '/').'=.*$/m', $content)) {
                $content = (string) preg_replace('/^'.preg_quote($key, '/').'=.*$/m', $line, $content);
            } else {
                $content = rtrim($content, "\n")."\n".$line."\n";
            }
        }

        file_put_contents($path, $content);
    }

    /** Pierwszy administrator: opcje → ENV → pytania interaktywne; pomijane przy --no-admin. */
    private function createAdministrator(): void
    {
        if ($this->option('no-admin')) {
            return;
        }

        $email = $this->option('admin-email') ?: env('ADMIN_EMAIL');
        $password = $this->option('admin-password') ?: env('ADMIN_PASSWORD');
        $name = $this->option('admin-name') ?: env('ADMIN_NAME', 'Administrator');

        if (($email === null || $password === null) && $this->input->isInteractive()) {
            $this->components->info('Pierwszy administrator panelu');
            $email ??= text('E-mail administratora', required: true, validate: fn (string $v) => filter_var($v, FILTER_VALIDATE_EMAIL) ? null : 'Podaj poprawny adres e-mail.');
            $password ??= \Laravel\Prompts\password('Hasło administratora', required: true, validate: fn (string $v) => strlen($v) >= 8 ? null : 'Hasło musi mieć co najmniej 8 znaków.');
            $name = $this->option('admin-name') ?: text('Nazwa wyświetlana', default: $name);
        }

        if (! is_string($email) || $email === '' || ! is_string($password) || $password === '') {
            $this->components->warn('Pominięto administratora (brak danych). Później: php artisan varsite:admin --email=... --password=...');

            return;
        }

        $this->callSilently('varsite:admin', [
            '--name' => $name,
            '--email' => $email,
            '--password' => $password,
        ]);
        $this->components->twoColumnDetail('<options=bold>Administrator</>', sprintf('%s <%s>', $name, $email));
    }

    /**
     * Doposaża app/Models/User.php w wymagania platformy (trait Sanctum, fillable role).
     * Deterministyczne dla stockowego Laravela; przy niestandardowym modelu — instrukcja ręczna.
     */
    private function ensureUserModel(): bool
    {
        $path = app_path('Models/User.php');

        if (! is_file($path)) {
            $this->components->warn('Nie znaleziono app/Models/User.php — dodaj trait Laravel\Sanctum\HasApiTokens oraz "role" do $fillable ręcznie.');

            return true;
        }

        $source = (string) file_get_contents($path);
        $changed = false;

        if (! str_contains($source, 'Laravel\Sanctum\HasApiTokens')) {
            $source = str_replace(
                'use Illuminate\Foundation\Auth\User as Authenticatable;',
                "use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;",
                $source,
                $count,
            );
            $changed = $changed || $count > 0;
        }

        if (! preg_match('/^[ \t]+use [^;]*HasApiTokens/m', $source)) {
            if (str_contains($source, 'use HasFactory,')) {
                $source = str_replace('use HasFactory,', 'use HasApiTokens, HasFactory,', $source);
                $changed = true;
            } elseif (str_contains($source, 'use HasFactory;')) {
                $source = str_replace('use HasFactory;', 'use HasApiTokens, HasFactory;', $source);
                $changed = true;
            } else {
                $this->components->warn('Nie udało się automatycznie dodać traitu HasApiTokens — dodaj go w app/Models/User.php.');
            }
        }

        if (! str_contains($source, "'role'")) {
            $source = str_replace("'name',", "'name',
        'role',", $source, $count);
            $changed = $changed || $count > 0;
        }

        if ($changed) {
            file_put_contents($path, $source);
        }

        return true;
    }
}
