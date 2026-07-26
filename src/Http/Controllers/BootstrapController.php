<?php

declare(strict_types=1);

namespace Varsite\Platform\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Varsite\Platform\Contracts\PlatformModule;
use Varsite\Platform\Http\Concerns\CachesContract;
use Varsite\Platform\Capabilities\CapabilityRegistry;
use Varsite\Platform\Support\ModuleManager;
use Varsite\Platform\Support\Rbac;

/**
 * GET /api/v1/admin/bootstrap — kernel API platformy.
 *
 * Publiczny, wersjonowany kontrakt dla WSZYSTKICH klientów: panelu, aplikacji
 * mobilnych, frontendów SSR, CLI i narzędzi, które dopiero powstaną. Odpowiada
 * na pytanie „jakie możliwości udostępnia ta instalacja platformy", a nie
 * „jak ma wyglądać panel" — prezentacja należy do klienta.
 *
 * Nawigacja NIE jest osobnym bytem w kontrakcie: klient wyprowadza ją z możliwości
 * (grupowanie po `owner`, etykieta grupy z `modules[].label`, ścieżka z `links.open`).
 * Dzięki temu ścieżka, menu i uprawnienia nie mogą się rozjechać — jest jedno źródło.
 *
 * Zwraca wyłącznie LEKKI manifest. Szczegóły (kolumny, pola, konfiguracja
 * widgetu) klient pobiera leniwie przez `links.declaration`, gdy realnie ich
 * potrzebuje. Dzięki temu rozmiar odpowiedzi rośnie z liczbą możliwości
 * kilkudziesięcioma bajtami na wpis, a nie pełną deklaracją.
 *
 * Zasady zgodności (kontrakt nie może się psuć przez lata):
 *  - `contract.version` rośnie tylko przy zmianie łamiącej,
 *  - nowe pola są wyłącznie DODAWANE,
 *  - nieznany `kind` możliwości klient ma zignorować — dzięki temu kolejne
 *    rodzaje rejestrów (widgety, ustawienia, wyszukiwarki) nie wymagają
 *    żadnej zmiany w tym kontrakcie ani w istniejących klientach.
 */
final class BootstrapController
{
    use CachesContract;

    public function __invoke(
        Request $request,
        ModuleManager $modules,
        CapabilityRegistry $capabilities,
        Rbac $rbac,
    ): JsonResponse {
        $user = $request->user();
        $permissions = $rbac->permissionsFor($user);

        $installed = array_map(
            static fn (PlatformModule $m): array => [
                'key' => $m->key(),
                'label' => $m->label(),
                'version' => $m->version(),
            ],
            array_values($modules->all()),
        );

        $payload = [
            'contract' => [
                'version' => (string) config('platform.contract.version'),
            ],
            'app' => [
                'name' => (string) config('app.name'),
            ],
            'user' => [
                'id' => $user->getKey(),
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role ?? 'owner',
            ],
            'permissions' => $permissions,
            'modules' => $installed,
            'capabilities' => $capabilities->manifest($permissions),
        ];

        return $this->contractResponse($request, $payload, sprintf(
            '%s|%s|%s|%s',
            (string) config('platform.contract.version'),
            $capabilities->fingerprint(),
            implode(',', array_map(static fn (array $m): string => $m['key'].'@'.$m['version'], $installed)),
            implode(',', $permissions),
        ));
    }
}
