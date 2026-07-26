<?php

declare(strict_types=1);

namespace Varsite\Platform\Support;

use Varsite\Platform\Contracts\MediaLibrary;

/**
 * Zachowanie biblioteki mediów, gdy moduł Media nie jest zainstalowany.
 *
 * Kontrakty opcjonalne mają w rdzeniu domyślną implementację pustą (null object),
 * dzięki czemu moduł korzystający z cudzej możliwości DZIAŁA DALEJ po jej
 * odinstalowaniu — po prostu bez tej funkcji. Alternatywa (wyjątek braku
 * wiązania) oznaczałaby twardą zależność między modułami tylnymi drzwiami.
 */
final class NullMediaLibrary implements MediaLibrary
{
    public function find(int $id): ?MediaReference
    {
        return null;
    }
}
