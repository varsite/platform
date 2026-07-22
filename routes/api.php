<?php

declare(strict_types=1);

use Varsite\Platform\Http\Controllers\AuthController;
use Varsite\Platform\Http\Controllers\BootstrapController;
use Varsite\Platform\Http\Controllers\HealthController;
use Varsite\Platform\Routing\ScopedRoutes;

/**
 * Trasy API Core (właściciel: "core"). Wersjonowanie w URI (/api/v1).
 * Definicja przez ScopedRoutes — rejestr własności + wykrywanie kolizji.
 */
return static function (ScopedRoutes $r): void {
    $r->prefix('api/v1')->group(function (ScopedRoutes $r): void {
        $r->get('health', HealthController::class);

        $r->middleware('throttle:10,1')->post('auth/login', [AuthController::class, 'login']);
        $r->middleware('auth:sanctum')->post('auth/logout', [AuthController::class, 'logout']);
        $r->middleware('auth:sanctum')->get('admin/bootstrap', BootstrapController::class);
    });
};
