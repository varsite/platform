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
    /** Techniczny identyfikator modułu, np. "audio". Prefiks wszystkich jego kluczy. */
    public function key(): string;

    /**
     * Nazwa modułu widoczna dla użytkownika, np. "Audio", "Biblioteka mediów".
     * To metadana TOŻSAMOŚCI modułu, a nie prezentacji — klienci używają jej
     * do grupowania możliwości (sidebar panelu, sekcje w CLI, ekrany mobilne).
     */
    public function label(): string;

    public function version(): string;

    /** @return array<int, string> uprawnienia do RBAC */
    public function permissions(): array;
}
