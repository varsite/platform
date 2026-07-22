<?php

declare(strict_types=1);

namespace Varsite\Platform\Support;

/**
 * Referencja do pliku Media — DTO kontraktu cross-module (§0.8).
 * Minimalny (JIT): tylko to, czego konsumenci potrzebują teraz. Rozszerzalny addytywnie
 * (np. o waveform/thumbnail), gdy pojawi się realna potrzeba.
 */
final readonly class MediaReference
{
    public function __construct(
        public int $id,
        public string $url,
        public string $mimeType,
        public ?float $duration,
    ) {}
}
