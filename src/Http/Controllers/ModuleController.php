<?php

declare(strict_types=1);

namespace Varsite\Platform\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Varsite\Platform\Contracts\ModuleManifest;
use Varsite\Platform\Enums\ModuleStatus;
use Varsite\Platform\Support\ModuleManager;
use Varsite\Platform\Support\ModuleRegistry;

/**
 * Centrum zarządzania modułami.
 *
 * Widok powstaje ze SKLEJENIA dwóch światów: manifest (tożsamość, z kodu)
 * i inwentarz (stan, z bazy). Żaden z nich nie kopiuje drugiego — N14.
 */
final class ModuleController
{
    public function index(Request $request, ModuleManager $manager, ModuleRegistry $registry): JsonResponse
    {
        Gate::authorize('platform.modules');

        $state = $registry->all();
        $timings = $manager->timings();
        $failures = $manager->failures();
        $generation = $this->generation();

        $rows = [];

        foreach ($manager->discovered() as $manifest) {
            $row = $state[$manifest->key] ?? null;
            $status = $registry->statusOf($manifest->key);

            $rows[] = [
                'id' => $manifest->key,
                'key' => $manifest->key,

                // ── z manifestu: kim jest moduł ────────────────────────────
                'name' => $manifest->name,
                'description' => $manifest->description,
                'author' => $manifest->author,
                'homepage' => $manifest->homepage,
                'section' => $manifest->section,
                'icon' => $manifest->icon,
                'version' => $manifest->version,
                'requires_generation' => $manifest->requiresGeneration,
                'permissions_count' => count($manifest->permissions),

                // ── z inwentarza: co się z nim dzieje ──────────────────────
                'status' => $status->value,
                'installed_version' => $row->installed_version ?? null,
                'generation' => $row->generation ?? null,
                'installed_at' => $row->installed_at ?? null,
                'activated_at' => $row->activated_at ?? null,
                'deactivated_at' => $row->deactivated_at ?? null,
                'status_message' => $failures[$manifest->key] ?? ($row->status_message ?? null),

                // ── wyliczane: różnice między deklaracją a stanem ──────────
                'needs_update' => $row !== null && (string) $row->installed_version !== $manifest->version,
                'compatible' => $this->isCompatible($manifest, $generation),
                'boot_time_ms' => $timings[$manifest->key] ?? null,
                'dependents' => $registry->dependentsOf($manifest->key),
            ];
        }

        usort($rows, static fn (array $a, array $b): int => [$a['section'], $a['name']] <=> [$b['section'], $b['name']]);

        return response()->json([
            'data' => $rows,
            'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => count($rows), 'total' => count($rows)],
        ]);
    }

    /** Zmiana aktywności. Jedyna modyfikacja modułu dostępna z panelu. */
    public function update(Request $request, ModuleManager $manager, ModuleRegistry $registry, string $module): JsonResponse
    {
        Gate::authorize('platform.modules');

        $manifest = $manager->discovered()[$module] ?? null;

        if ($manifest === null) {
            return response()->json(['message' => 'Nie znaleziono modułu.'], 404);
        }

        $data = $request->validate(['status' => ['required', 'in:active,disabled']]);
        $target = ModuleStatus::from($data['status']);

        if ($target === ModuleStatus::Disabled) {
            // Moduł, od którego zależą inne aktywne, nie może zostać wyłączony —
            // inaczej zostawiłby je bez kontraktu, na którym polegają.
            $dependents = $registry->dependentsOf($module);

            if ($dependents !== []) {
                return response()->json([
                    'message' => sprintf(
                        'Nie można wyłączyć modułu „%s" — zależą od niego aktywne moduły: %s.',
                        $manifest->name,
                        implode(', ', $dependents),
                    ),
                ], 422);
            }

            $registry->deactivate($module);
        } else {
            $registry->activate($module);
        }

        return response()->json(['data' => [
            'key' => $module,
            'status' => $target->value,
            // Rejestracja modułów odbywa się przy bootowaniu, więc zmiana
            // uwidacznia się od następnego żądania.
            'requires_reload' => true,
        ]]);
    }

    private function generation(): ?string
    {
        $version = (string) config('platform.contract.version', '');

        return $version === '' ? null : $version;
    }

    private function isCompatible(ModuleManifest $manifest, ?string $generation): bool
    {
        if ($manifest->requiresGeneration === null || $generation === null) {
            return true; // brak deklaracji = brak podstaw do orzekania niezgodności
        }

        return true; // pełne porównanie zakresów wejdzie z pierwszym realnym konfliktem
    }
}
