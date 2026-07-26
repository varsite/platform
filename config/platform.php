<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Wersja kontraktu platformy (kernel API)
    |--------------------------------------------------------------------------
    | Zwracana w /admin/bootstrap. Rośnie WYŁĄCZNIE przy zmianie łamiącej —
    | dodawanie pól i nowych rodzajów możliwości jest wstecznie zgodne.
    */
    'contract' => [
        'version' => '2.0',
    ],


    /*
    |--------------------------------------------------------------------------
    | Uwierzytelnianie panelu
    |--------------------------------------------------------------------------
    | Czas życia tokenów Sanctum wydawanych przy logowaniu (dni).
    | 0 = bezterminowo (niezalecane w produkcji).
    */
    'auth' => [
        'token_lifetime_days' => (int) env('VARSITE_TOKEN_TTL_DAYS', 30),

        /*
        | Role o pełnym dostępie — otrzymują wszystkie uprawnienia istniejące
        | w instalacji, także z modułów doinstalowanych później.
        */
        'superuser_roles' => ['owner', 'Właściciel'],

        /*
        | Uprawnienia rdzenia (moduły deklarują własne w PlatformModule).
        */
        'core_permissions' => ['platform.settings', 'platform.users'],

        /*
        | Mapa rola => uprawnienia. Identyfikatory są nieprzezroczyste — Core
        | nie nadaje im znaczenia, jedynie sprawdza przynależność.
        */
        'roles' => [
            'editor' => ['audio.view', 'audio.create', 'audio.update', 'media.view', 'media.upload'],
            'viewer' => ['audio.view', 'media.view'],
        ],
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
