<?php

declare(strict_types=1);

namespace Varsite\Platform\Support;

use Varsite\Platform\Contracts\PlatformModule;

/**
 * Rejestr modułów platformy. Fundament rozszerzalności — moduły rejestrują się tutaj
 * (przez swoje providery). W Fazie 0 pusty; wypełniany, gdy powstaną realne moduły (JIT).
 */
final class ModuleManager
{
    /** @var array<string, PlatformModule> */
    private array $modules = [];

    public function register(PlatformModule $module): void
    {
        $this->modules[$module->key()] = $module;
    }

    public function get(string $key): ?PlatformModule
    {
        return $this->modules[$key] ?? null;
    }

    /** @return array<string, PlatformModule> */
    public function all(): array
    {
        return $this->modules;
    }

    /** @return list<string> identyfikatory zarejestrowanych modułów */
    public function ids(): array
    {
        return array_keys($this->modules);
    }
}
