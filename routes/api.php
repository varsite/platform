<?php

declare(strict_types=1);

use Varsite\Platform\Http\Controllers\AuthController;
use Varsite\Platform\Http\Controllers\BootstrapController;
use Varsite\Platform\Http\Controllers\CapabilityController;
use Varsite\Platform\Http\Controllers\HealthController;
use Varsite\Platform\Http\Controllers\HealthSummaryController;
use Varsite\Platform\Http\Controllers\ModuleController;
use Varsite\Platform\Http\Controllers\SettingController;
use Varsite\Platform\Http\Controllers\UserController;
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
        $r->middleware('auth:sanctum')->get('admin/capabilities/{key}', CapabilityController::class);
        $r->middleware('auth:sanctum')->get('admin/health-summary', HealthSummaryController::class);

        $r->middleware('auth:sanctum')->get('admin/modules', [ModuleController::class, 'index']);
        $r->middleware('auth:sanctum')->patch('admin/modules/{module}', [ModuleController::class, 'update']);

        $r->middleware('auth:sanctum')->get('admin/settings/{key}', [SettingController::class, 'show']);
        $r->middleware('auth:sanctum')->put('admin/settings/{key}', [SettingController::class, 'update']);

        $r->middleware('auth:sanctum')->prefix('admin/users')->group(function (ScopedRoutes $r): void {
            $r->get('roles', [UserController::class, 'roles']);
            $r->get('/', [UserController::class, 'index']);
            $r->post('/', [UserController::class, 'store']);
            $r->get('{user}', [UserController::class, 'show']);
            $r->patch('{user}', [UserController::class, 'update']);
            $r->delete('{user}', [UserController::class, 'destroy']);
        });
    });
};
