<?php

declare(strict_types=1);

namespace Varsite\Platform\Routing;

use Closure;
use Illuminate\Routing\Router;

/**
 * Ograniczony interfejs definiowania tras dla modułów. Każdy verb jest
 * rejestrowany w RouteRegistry (wykrywanie kolizji) i delegowany do routera
 * Laravela. Pokrywa wzorce używane w platformie: prefix / middleware / group / verby.
 */
final class ScopedRoutes
{
    /**
     * @param list<string> $middleware
     * @param bool $routerWritable false przy zbudowanym route:cache — trasy już są
     *                             w cache; rejestrujemy wtedy TYLKO własność (registry),
     *                             aby audyt i varsite:routes działały, bez dublowania tras.
     */
    public function __construct(
        private readonly Router $router,
        private readonly RouteRegistry $registry,
        private readonly string $owner,
        private readonly string $prefix = '',
        private readonly array $middleware = [],
        private readonly bool $routerWritable = true,
    ) {}

    public function prefix(string $prefix): self
    {
        $merged = trim(trim($this->prefix, '/').'/'.trim($prefix, '/'), '/');

        return new self($this->router, $this->registry, $this->owner, $merged, $this->middleware, $this->routerWritable);
    }

    /** @param string|list<string> $middleware */
    public function middleware(string|array $middleware): self
    {
        $merged = array_values(array_unique([...$this->middleware, ...(array) $middleware]));

        return new self($this->router, $this->registry, $this->owner, $this->prefix, $merged, $this->routerWritable);
    }

    /** @param Closure(self): void $routes */
    public function group(Closure $routes): void
    {
        $routes($this);
    }

    public function get(string $uri, mixed $action): void
    {
        $this->add('GET', $uri, $action);
    }

    public function post(string $uri, mixed $action): void
    {
        $this->add('POST', $uri, $action);
    }

    public function put(string $uri, mixed $action): void
    {
        $this->add('PUT', $uri, $action);
    }

    public function patch(string $uri, mixed $action): void
    {
        $this->add('PATCH', $uri, $action);
    }

    public function delete(string $uri, mixed $action): void
    {
        $this->add('DELETE', $uri, $action);
    }

    private function add(string $method, string $uri, mixed $action): void
    {
        $full = trim(trim($this->prefix, '/').'/'.trim($uri, '/'), '/');

        // Najpierw rejestr (kolizja = wyjątek, trasa NIE trafia do routera).
        $this->registry->record($this->owner, $method, $full);

        if (! $this->routerWritable) {
            return; // trasy w route:cache — router nietykalny, własność zapisana
        }

        $route = $this->router->addRoute([$method], $full, $action);

        if ($this->middleware !== []) {
            $route->middleware($this->middleware);
        }
    }
}
