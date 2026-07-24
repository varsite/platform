<?php

declare(strict_types=1);

namespace Varsite\Platform\Http\Concerns;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Strategia cache kontraktu platformy (kernel API).
 *
 * ETag budowany jest z: wersji kontraktu + odcisku rejestru możliwości +
 * wersji zainstalowanych modułów + uprawnień odbiorcy. Dzięki temu:
 *
 *  - instalacja/usunięcie/aktualizacja modułu automatycznie unieważnia cache
 *    wszystkich klientów (bez ręcznej inwalidacji i bez zdarzeń),
 *  - użytkownicy o różnych uprawnieniach nie współdzielą wpisów,
 *  - klient odpytuje warunkowo (If-None-Match) i zwykle dostaje 304.
 *
 * `private` jest świadomym wyborem: manifest zależy od uprawnień. Deklaracje
 * możliwości są od użytkownika niezależne, więc w przyszłości można je podać
 * jako `public` i wystawić przez CDN — struktura odpowiedzi tego nie blokuje.
 */
trait CachesContract
{
    /** @param array<string,mixed> $payload */
    protected function contractResponse(Request $request, array $payload, string $seed, bool $shareable = false): JsonResponse
    {
        $etag = '"'.hash('xxh128', $seed).'"';

        if (in_array($etag, $this->requestedEtags($request), true)) {
            return response()->json(null, 304)->setEtag($etag, true);
        }

        return response()
            ->json(['data' => $payload])
            ->setEtag($etag, true)
            ->header('Cache-Control', $shareable
                ? 'public, max-age=0, must-revalidate'
                : 'private, max-age=0, must-revalidate');
    }

    /** @return list<string> */
    private function requestedEtags(Request $request): array
    {
        $header = (string) $request->header('If-None-Match', '');

        if ($header === '') {
            return [];
        }

        return array_map(
            static fn (string $tag): string => trim(str_replace('W/', '', $tag)),
            explode(',', $header),
        );
    }
}
