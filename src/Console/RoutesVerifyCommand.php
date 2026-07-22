<?php

declare(strict_types=1);

namespace Varsite\Platform\Console;

use Illuminate\Console\Command;
use Illuminate\Routing\Router;
use Varsite\Platform\Routing\RouteRegistry;

/**
 * Weryfikacja integralności routingu (CI / pre-deploy).
 * Kolizje właścicieli wybuchają już przy boot; ta komenda dodatkowo
 * wykrywa trasy /api/* zarejestrowane z pominięciem registrara.
 */
final class RoutesVerifyCommand extends Command
{
    protected $signature = 'varsite:routes:verify';

    protected $description = 'Weryfikuje integralność routingu platformy (kolizje, trasy poza registrarem)';

    public function handle(Router $router, RouteRegistry $registry): int
    {
        $violations = $registry->auditRouter($router);

        if ($violations !== []) {
            foreach ($violations as $violation) {
                $this->error($violation);
            }

            return self::FAILURE;
        }

        $this->info(sprintf('Routing spójny: %d tras, wszystkie z właścicielem.', count($registry->all())));

        return self::SUCCESS;
    }
}
