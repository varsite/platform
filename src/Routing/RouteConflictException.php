<?php

declare(strict_types=1);

namespace Varsite\Platform\Routing;

use RuntimeException;

/** Kolizja tras między właścicielami (Core/moduły). Integralność routingu = twarda gwarancja platformy. */
final class RouteConflictException extends RuntimeException
{
    public static function duplicate(string $method, string $uri, string $existingOwner, string $newOwner): self
    {
        return new self(sprintf(
            'Konflikt trasy: [%s /%s] jest już zarejestrowana przez "%s"; "%s" nie może jej nadpisać.',
            $method,
            $uri,
            $existingOwner,
            $newOwner,
        ));
    }

    public static function outsideRegistrar(string $method, string $uri): self
    {
        return new self(sprintf(
            'Trasa API [%s /%s] została zarejestrowana poza ModuleRouteRegistrar. '
            .'Wszystkie trasy /api/* muszą przechodzić przez registrar (integralność platformy).',
            $method,
            $uri,
        ));
    }
}
