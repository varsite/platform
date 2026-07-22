<?php

declare(strict_types=1);

namespace Varsite\Platform\Routing;

use Closure;
use Illuminate\Routing\Router;

/**
 * Jedyny punkt rejestracji tras API platformy (ADR: Core-first routing).
 * Moduł w swoim ServiceProviderze woła:
 *
 *     $registrar->register('audio', require __DIR__.'/../routes/api.php');
 *
 * gdzie plik tras zwraca Closure(ScopedRoutes $r): void.
 * Kolizje (Core↔moduł, moduł↔moduł, duplikat wewnętrzny) rzucają
 * RouteConflictException już na etapie boot — trasa-intruz nie powstaje.
 */
final class ModuleRouteRegistrar
{
    public function __construct(
        private readonly Router $router,
        private readonly RouteRegistry $registry,
    ) {}

    /** @param Closure(ScopedRoutes): void $definition */
    public function register(string $owner, Closure $definition): void
    {
        $definition(new ScopedRoutes(
            $this->router,
            $this->registry,
            $owner,
            routerWritable: ! app()->routesAreCached(),
        ));
    }

    public function registry(): RouteRegistry
    {
        return $this->registry;
    }
}
