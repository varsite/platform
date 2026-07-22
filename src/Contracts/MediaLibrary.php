<?php

declare(strict_types=1);

namespace Varsite\Platform\Contracts;

use Varsite\Platform\Support\MediaReference;

/**
 * Kontrakt dostępu do biblioteki mediów dla innych modułów (§0.8).
 * Implementowany przez moduł Media; konsumowany przez moduły domenowe (np. Audio)
 * WYŁĄCZNIE przez ten interfejs — nigdy przez model Media wprost.
 */
interface MediaLibrary
{
    public function find(int $id): ?MediaReference;
}
