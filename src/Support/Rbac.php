<?php

declare(strict_types=1);

namespace Varsite\Platform\Support;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Kontrola uprawnień platformy (N5: Core jest ignorantyczny wobec domen).
 *
 * Uprawnienie to NIEPRZEZROCZYSTY identyfikator — Core nigdy go nie parsuje,
 * nie grupuje po prefiksie i nie nadaje mu znaczenia. Odpowiada wyłącznie na
 * pytanie „czy ten użytkownik posiada uprawnienie X?".
 *
 * Łańcuch decyzyjny należy w całości do Core:
 *
 *     użytkownik → rola → zestaw uprawnień → możliwość
 *
 * Moduł deklaruje jedynie listę identyfikatorów w PlatformModule::permissions()
 * i nigdy nie wie, jakie role istnieją ani kto z nich korzysta.
 *
 * Model ról jest konfiguracyjny (config/platform.php → auth.roles). Świadomie
 * nie wprowadzamy tabel ról ani przypisań — wejdą wtedy, gdy pojawi się realna
 * potrzeba nadawania uprawnień per użytkownik (JIT).
 */
final class Rbac
{
    /**
     * Uprawnienia rdzenia — WARTOŚCI DOMYŚLNE W KODZIE.
     *
     * Konfiguracja może je rozszerzyć, ale nie warunkuje ich istnienia.
     * Opublikowany config/platform.php w istniejącej instalacji nie zawiera
     * kluczy dodanych w nowszym wydaniu, więc poleganie wyłącznie na nim
     * wyłączałoby funkcje po każdej aktualizacji.
     */
    private const CORE_PERMISSIONS = ['platform.settings', 'platform.users', 'platform.modules'];

    /** Role o pełnym dostępie — również z wartością domyślną w kodzie. */
    private const SUPERUSER_ROLES = ['owner'];

    public function __construct(private readonly ModuleManager $modules) {}

    /** Rola przypisana użytkownikowi; brak roli = brak uprawnień. */
    public function roleOf(Authenticatable $user): ?string
    {
        $role = $user->role ?? null;

        return is_string($role) && $role !== '' ? $role : null;
    }

    /**
     * Wszystkie uprawnienia istniejące w tej instalacji: rdzeń + moduły.
     *
     * @return list<string>
     */
    public function available(): array
    {
        // Suma: to, co zna kod, plus ewentualne rozszerzenia z konfiguracji.
        $permissions = array_unique([
            ...self::CORE_PERMISSIONS,
            ...(array) config('platform.auth.core_permissions', []),
        ]);

        foreach ($this->modules->permissions() as $permission) {
            $permissions[] = $permission;
        }

        return array_values(array_unique($permissions));
    }

    /**
     * Uprawnienia użytkownika. Rola oznaczona jako pełnoprawna otrzymuje
     * wszystko, co istnieje w instalacji — w tym uprawnienia modułów
     * doinstalowanych później, bez żadnej migracji ani synchronizacji.
     *
     * @return list<string>
     */
    public function permissionsFor(?Authenticatable $user): array
    {
        if ($user === null) {
            return [];
        }

        $role = $this->roleOf($user);

        if ($role === null) {
            return [];
        }

        $superusers = array_unique([
            ...self::SUPERUSER_ROLES,
            ...(array) config('platform.auth.superuser_roles', []),
        ]);

        if (in_array($role, $superusers, true)) {
            return $this->available();
        }

        /** @var array<string, list<string>> $map */
        $map = (array) config('platform.auth.roles', []);

        return array_values(array_unique((array) ($map[$role] ?? [])));
    }

    /** @return list<string> role o pełnym dostępie w tej instalacji */
    public function superuserRoles(): array
    {
        return array_values(array_unique([
            ...self::SUPERUSER_ROLES,
            ...(array) config('platform.auth.superuser_roles', []),
        ]));
    }

    public function allows(?Authenticatable $user, string $permission): bool
    {
        return in_array($permission, $this->permissionsFor($user), true);
    }
}
