<?php

declare(strict_types=1);

namespace Varsite\Platform\Routing;

use Illuminate\Routing\Router;

/**
 * Rejestr własności tras platformy: METODA+URI → właściciel (core / id modułu).
 * Jedyne źródło prawdy o tym, kto zarejestrował którą trasę; wykrywa kolizje
 * w momencie rejestracji oraz audytuje spójność z routerem po boot.
 */
final class RouteRegistry
{
    /** @var array<string, string> klucz "METHOD uri" → owner */
    private array $routes = [];

    public function record(string $owner, string $method, string $uri): void
    {
        $key = $this->key($method, $uri);

        if (isset($this->routes[$key]) && $this->routes[$key] !== $owner) {
            throw RouteConflictException::duplicate($method, $uri, $this->routes[$key], $owner);
        }

        if (isset($this->routes[$key])) {
            // Ten sam właściciel dwa razy = błąd definicji modułu (duplikat wewnętrzny).
            throw RouteConflictException::duplicate($method, $uri, $this->routes[$key], $owner);
        }

        $this->routes[$key] = $owner;
    }

    public function ownerOf(string $method, string $uri): ?string
    {
        return $this->routes[$this->key($method, $uri)] ?? null;
    }

    /** @return array<string, string> */
    public function all(): array
    {
        return $this->routes;
    }

    /**
     * Audyt spójności: każda trasa /api/* obecna w routerze musi mieć właściciela w rejestrze.
     * Łapie trasy dodane z pominięciem registrara (np. bezpośrednio przez Route::).
     *
     * @return list<string> lista naruszeń (pusta = spójnie)
     */
    public function auditRouter(Router $router): array
    {
        $violations = [];

        foreach ($router->getRoutes()->getRoutes() as $route) {
            $uri = $route->uri();

            if (! str_starts_with($uri, 'api/')) {
                continue; // web/fallback poza zakresem rejestru API
            }

            foreach ($route->methods() as $method) {
                if ($method === 'HEAD') {
                    continue;
                }

                if ($this->ownerOf($method, $uri) === null) {
                    $violations[] = sprintf('[%s /%s] poza registrarem', $method, $uri);
                }
            }
        }

        return $violations;
    }

    private function key(string $method, string $uri): string
    {
        return strtoupper($method).' '.trim($uri, '/');
    }
}
