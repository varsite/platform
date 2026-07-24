<?php

declare(strict_types=1);

namespace Varsite\Platform\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Varsite\Platform\Http\Concerns\CachesContract;
use Varsite\Platform\Capabilities\CapabilityRegistry;

/**
 * Deklaracja pojedynczej możliwości (drugi krok wzorca:
 * manifest → DEKLARACJA → dane).
 *
 * Klient pobiera ją leniwie, dopiero gdy realnie korzysta z możliwości —
 * dzięki temu bootstrap pozostaje stały niezależnie od liczby modułów.
 */
final class CapabilityController
{
    use CachesContract;

    public function __invoke(Request $request, CapabilityRegistry $registry, string $key): JsonResponse
    {
        $permissions = ['*']; // RBAC granularne: F2
        $capability = $registry->visibleTo($permissions)[$key] ?? null;

        if ($capability === null) {
            // 404 także wtedy, gdy możliwość istnieje, ale odbiorca jej nie widzi —
            // nie ujawniamy istnienia zasobów spoza uprawnień.
            return response()->json(['message' => 'Not Found.'], 404);
        }

        return $this->contractResponse(
            $request,
            $capability->declaration(),
            sprintf(
                '%s|%s|%s',
                (string) config('platform.contract.version'),
                $key,
                $registry->fingerprint(),
            ),
        );
    }
}
