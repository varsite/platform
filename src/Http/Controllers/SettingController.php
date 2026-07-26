<?php

declare(strict_types=1);

namespace Varsite\Platform\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Varsite\Platform\Capabilities\CapabilityRegistry;
use Varsite\Platform\Capabilities\SettingCapability;
use Varsite\Platform\Support\Rbac;
use Varsite\Platform\Support\Settings;

/**
 * Odczyt i zapis ustawień — jeden generyczny endpoint dla WSZYSTKICH grup,
 * obecnych i przyszłych.
 *
 * Core nie zna znaczenia żadnego pola: bierze deklarację modułu, waliduje jego
 * regułami i zapisuje. Nowa grupa ustawień w module działa bez zmiany tutaj.
 */
final class SettingController
{
    public function show(Request $request, CapabilityRegistry $registry, Rbac $rbac, Settings $settings, string $key): JsonResponse
    {
        $capability = $this->resolve($request, $registry, $rbac, $key);

        return response()->json(['data' => $settings->all($capability->key())]);
    }

    public function update(Request $request, CapabilityRegistry $registry, Rbac $rbac, Settings $settings, string $key): JsonResponse
    {
        $capability = $this->resolve($request, $registry, $rbac, $key);
        $rules = $capability->validationRules();

        // Zapisujemy wyłącznie pola objęte deklaracją — ładunek spoza niej jest
        // ignorowany, więc klient nie może przemycić klucza, którego moduł nie zna.
        $payload = $rules === []
            ? $request->all()
            : Validator::make($request->all(), $rules)->validate();

        $settings->save($capability->key(), $payload);

        return response()->json(['data' => $settings->all($capability->key())]);
    }

    private function resolve(Request $request, CapabilityRegistry $registry, Rbac $rbac, string $key): SettingCapability
    {
        $capability = $registry->visibleTo($rbac->permissionsFor($request->user()))[$key] ?? null;

        abort_unless($capability instanceof SettingCapability, 404, 'Not Found.');

        return $capability;
    }
}
