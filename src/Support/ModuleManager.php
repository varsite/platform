<?php

declare(strict_types=1);

namespace Varsite\Platform\Support;

use Closure;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;
use Varsite\Platform\Contracts\ModuleManifest;
use Varsite\Platform\Contracts\PlatformModule;
use Varsite\Platform\Enums\ModuleStatus;

/**
 * Orkiestrator modułów — centralne miejsce wiedzy o ich życiu.
 *
 * Provider mówi wyłącznie: „to jest mój manifest i to chcę zarejestrować".
 * Wszystko pozostałe należy do rdzenia i może rosnąć bez dotykania modułów:
 * decyzja o aktywności, izolacja błędów, pomiar czasu bootowania, diagnostyka,
 * a w przyszłości leniwe bootowanie czy telemetria.
 *
 * Moduł wyłączony nie rejestruje NICZEGO: domknięcie po prostu się nie wykonuje,
 * więc nie powstają trasy, możliwości, polityki, listenery ani komendy. Migracje,
 * dane i kod w vendor/ pozostają nietknięte.
 */
final class ModuleManager
{
    /** @var array<string, ModuleManifest> moduły aktywne w tym żądaniu */
    private array $active = [];

    /** @var array<string, ModuleManifest> wszystkie wykryte, niezależnie od stanu */
    private array $discovered = [];

    /** @var array<string, float> czas rejestracji w milisekundach */
    private array $timings = [];

    /** @var array<string, string> moduły, których rejestracja rzuciła wyjątkiem */
    private array $failures = [];

    /** @var array<string, ModuleStatus>|null stan z inwentarza; null = jeszcze nieodczytany */
    private ?array $statuses = null;

    /**
     * Rejestracja modułu. Domknięcie wykonuje się WYŁĄCZNIE dla modułu aktywnego.
     *
     * @param Closure():void $register trasy, polityki, możliwości modułu
     */
    public function module(PlatformModule $module, ?Closure $register = null): void
    {
        $manifest = $module->manifest();
        $this->discovered[$manifest->key] = $manifest;

        if (! $this->isActive($manifest->key)) {
            return;
        }

        $this->active[$manifest->key] = $manifest;

        if ($register === null) {
            return;
        }

        $started = microtime(true);

        try {
            $register();
        } catch (Throwable $e) {
            // Awaria jednego modułu nie może wywrócić całej platformy. Zapisujemy
            // ją jako fakt diagnostyczny — to podstawa przyszłego stanu "broken".
            unset($this->active[$manifest->key]);
            $this->failures[$manifest->key] = $e->getMessage();

            Log::error('Rejestracja modułu nie powiodła się.', [
                'module' => $manifest->key,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return;
        }

        $this->timings[$manifest->key] = round((microtime(true) - $started) * 1000, 2);
    }

    /** @return array<string, ModuleManifest> */
    public function all(): array
    {
        ksort($this->active);

        return $this->active;
    }

    /** @return array<string, ModuleManifest> również wyłączone i te, które zawiodły */
    public function discovered(): array
    {
        ksort($this->discovered);

        return $this->discovered;
    }

    /** @return list<string> */
    public function ids(): array
    {
        return array_keys($this->all());
    }

    public function get(string $key): ?ModuleManifest
    {
        return $this->all()[$key] ?? null;
    }

    public function has(string $key): bool
    {
        return isset($this->all()[$key]);
    }

    /** Wszystkie uprawnienia deklarowane przez aktywne moduły. @return list<string> */
    public function permissions(): array
    {
        $permissions = [];

        foreach ($this->all() as $manifest) {
            foreach ($manifest->permissions as $permission) {
                $permissions[] = $permission;
            }
        }

        return array_values(array_unique($permissions));
    }

    /** @return array<string, float> czasy rejestracji — dane dla diagnostyki */
    public function timings(): array
    {
        return $this->timings;
    }

    /** @return array<string, string> moduły, których rejestracja zawiodła */
    public function failures(): array
    {
        return $this->failures;
    }

    /**
     * Czy moduł jest aktywny wg inwentarza.
     *
     * Brak tabeli (instalacja, migracje) albo brak rekordu oznacza aktywność —
     * moduł świeżo dodany przez Composera ma działać bez ręcznego włączania.
     * Wyłączenie jest zawsze decyzją zapisaną jawnie.
     */
    private function isActive(string $key): bool
    {
        if ($this->statuses === null) {
            $this->statuses = $this->loadStatuses();
        }

        $status = $this->statuses[$key] ?? null;

        return $status === null || $status->isActive();
    }

    /** @return array<string, ModuleStatus> */
    private function loadStatuses(): array
    {
        try {
            if (! InstallationState::hasTable('platform_modules')) {
                return [];
            }

            $rows = \Illuminate\Support\Facades\DB::table('platform_modules')->pluck('status', 'key');
        } catch (Throwable) {
            return []; // brak bazy — wszystko aktywne
        }

        $statuses = [];

        foreach ($rows as $key => $status) {
            $parsed = ModuleStatus::tryFrom((string) $status);

            if ($parsed !== null) {
                $statuses[(string) $key] = $parsed;
            }
        }

        return $statuses;
    }
}
