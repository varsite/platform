<?php

declare(strict_types=1);

namespace Varsite\Platform\Contracts;

/**
 * Kontrakt modułu platformy. Każdy moduł (pakiet) implementuje go, aby zadeklarować
 * swoje możliwości. Rdzeń (ModuleManager) zbiera moduły; RBAC seeduje uprawnienia,
 * a OpenAPI składa się z fragmentów modułów. Dodanie modułu nie dotyka istniejących.
 *
 * Extension point (JIT): interfejs istnieje w Fazie 0; konkretne moduły powstają później.
 */
interface PlatformModule
{
    public function key(): string;

    public function version(): string;

    /** @return array<int, string> uprawnienia do RBAC */
    public function permissions(): array;
}
