<?php

declare(strict_types=1);

namespace Varsite\Platform\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Varsite\Platform\Support\ModuleManager;
use Varsite\Platform\Support\NavRegistry;

/**
 * GET /api/v1/admin/bootstrap (D4): jedno wywołanie po zalogowaniu.
 * Zwraca użytkownika, aktywne moduły, nawigację z rejestru (F4) i uprawnienia.
 * Uprawnienia R2 = zgrubne (rola właściciela, wildcard); granularne RBAC → kolejne wydania.
 */
final class BootstrapController
{
    public function __invoke(Request $request, ModuleManager $modules, NavRegistry $nav): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'data' => [
                'user' => [
                    'id' => $user->getKey(),
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role ?? 'owner',
                ],
                'modules' => $modules->ids(),
                'nav' => $nav->toArray(),
                'permissions' => ['*'],
            ],
        ]);
    }
}
