<?php

declare(strict_types=1);

namespace Varsite\Platform\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;
use Varsite\Platform\Contracts\ModuleManifest;
use Varsite\Platform\Enums\ModuleStatus;

/**
 * Inwentarz modułów — właściciel stanu instalacji.
 *
 * Odpowiada wyłącznie na pytanie „co się dzieje z modułem W TEJ instalacji".
 * Nazwa, opis, ikona i sekcja pochodzą z manifestu i NIE są tu kopiowane (N14);
 * widok administratora powstaje ze sklejenia obu światów w locie.
 */
final class ModuleRegistry
{
    private const TABLE = 'platform_modules';

    public function __construct(private readonly ModuleManager $modules) {}

    /**
     * Stan instalacji dla każdego wykrytego modułu, uzupełniony o brakujące
     * rekordy. Wywoływane przez varsite:update i ekran modułów.
     *
     * @return array<string, object>
     */
    public function all(): array
    {
        if (! $this->available()) {
            return [];
        }

        return DB::table(self::TABLE)->get()->keyBy('key')->all();
    }

    public function get(string $key): ?object
    {
        return $this->all()[$key] ?? null;
    }

    public function statusOf(string $key): ModuleStatus
    {
        $row = $this->get($key);

        return $row === null
            ? ModuleStatus::Installed
            : (ModuleStatus::tryFrom((string) $row->status) ?? ModuleStatus::Installed);
    }

    /**
     * Uzupełnia inwentarz o moduły wykryte, których jeszcze w nim nie ma,
     * i odnotowuje zmianę wersji. Idempotentne.
     *
     * @return array{added: list<string>, updated: list<string>}
     */
    public function synchronize(?string $generation = null): array
    {
        if (! $this->available()) {
            return ['added' => [], 'updated' => []];
        }

        $existing = $this->all();
        $added = [];
        $updated = [];

        foreach ($this->modules->discovered() as $manifest) {
            $row = $existing[$manifest->key] ?? null;

            if ($row === null) {
                DB::table(self::TABLE)->insert([
                    'key' => $manifest->key,
                    'status' => ModuleStatus::Active->value,
                    'installed_version' => $manifest->version,
                    'generation' => $generation,
                    'installed_at' => now(),
                    'activated_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $added[] = $manifest->key;

                continue;
            }

            // Wersja z manifestu różna od zapisanej = moduł został zaktualizowany.
            if ((string) $row->installed_version !== $manifest->version) {
                DB::table(self::TABLE)->where('key', $manifest->key)->update([
                    'installed_version' => $manifest->version,
                    'generation' => $generation ?? $row->generation,
                    'updated_at' => now(),
                ]);
                $updated[] = $manifest->key;
            }
        }

        return ['added' => $added, 'updated' => $updated];
    }

    /** Zapisuje błędy rejestracji zgłoszone przez orkiestrator. */
    public function recordFailures(): void
    {
        if (! $this->available()) {
            return;
        }

        foreach ($this->modules->failures() as $key => $message) {
            DB::table(self::TABLE)->where('key', $key)->update([
                'status' => ModuleStatus::Broken->value,
                'status_message' => $message,
                'updated_at' => now(),
            ]);
        }
    }

    public function activate(string $key): void
    {
        $this->setStatus($key, ModuleStatus::Active, ['activated_at' => now(), 'status_message' => null]);
    }

    public function deactivate(string $key): void
    {
        $this->setStatus($key, ModuleStatus::Disabled, ['deactivated_at' => now()]);
    }

    /**
     * Moduły aktywne, które przestałyby działać po wyłączeniu wskazanego.
     *
     * Zależność wynika z manifestu Composera pakietu — moduł deklarujący
     * `varsite/<klucz>` w require nie może działać bez niego. Rdzeń nie zna
     * żadnego konkretnego modułu, czyta wyłącznie deklaracje.
     *
     * @return list<string>
     */
    public function dependentsOf(string $key): array
    {
        $dependents = [];

        foreach ($this->modules->all() as $manifest) {
            if ($manifest->key === $key) {
                continue;
            }

            if (in_array($key, $this->requirementsOf($manifest), true)) {
                $dependents[] = $manifest->key;
            }
        }

        return $dependents;
    }

    /** @return list<string> klucze modułów wymaganych przez dany pakiet */
    private function requirementsOf(ModuleManifest $manifest): array
    {
        $path = base_path('vendor/varsite/'.$manifest->key.'/composer.json');

        if (! is_file($path)) {
            return [];
        }

        try {
            /** @var array{require?: array<string, string>} $data */
            $data = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return [];
        }

        $required = [];

        foreach (array_keys($data['require'] ?? []) as $package) {
            if (str_starts_with($package, 'varsite/') && $package !== 'varsite/platform') {
                $required[] = substr($package, strlen('varsite/'));
            }
        }

        return $required;
    }

    /** @param array<string, mixed> $extra */
    private function setStatus(string $key, ModuleStatus $status, array $extra = []): void
    {
        if (! $this->available()) {
            return;
        }

        DB::table(self::TABLE)->updateOrInsert(
            ['key' => $key],
            array_merge([
                'status' => $status->value,
                'updated_at' => now(),
            ], $extra),
        );
    }

    private function available(): bool
    {
        try {
            return Schema::hasTable(self::TABLE);
        } catch (Throwable) {
            return false;
        }
    }
}
