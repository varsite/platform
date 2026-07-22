<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Uwierzytelnianie panelu
    |--------------------------------------------------------------------------
    | Czas życia tokenów Sanctum wydawanych przy logowaniu (dni).
    | 0 = bezterminowo (niezalecane w produkcji).
    */
    'auth' => [
        'token_lifetime_days' => (int) env('VARSITE_TOKEN_TTL_DAYS', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Frontendy serwowane przez Core (jeden webroot, ADR-0005)
    |--------------------------------------------------------------------------
    | Serwer WWW serwuje istniejące pliki bezpośrednio; index.html-e podaje
    | Core (FrontendController). Ścieżki nadpisywalne w wdrożeniu.
    */
    'frontends' => [
        'website' => env('PLATFORM_FRONTEND_WEBSITE', public_path()),
        'admin' => env('PLATFORM_FRONTEND_ADMIN'), // null = dystrybucja z pakietu Core
    ],

    // Wersja platformy (raportowana przez /api/v1/health).
    'version' => env('PLATFORM_VERSION', '0.0.0'),
];
