<?php

declare(strict_types=1);

namespace Varsite\Platform\Console;

use Illuminate\Console\Command;
use Varsite\Platform\Routing\RouteRegistry;

/** Listing tras platformy z właścicielem (core / moduł). */
final class PlatformRoutesCommand extends Command
{
    protected $signature = 'varsite:routes';

    protected $description = 'Wyświetla trasy API platformy wraz z właścicielem (Core/moduł)';

    public function handle(RouteRegistry $registry): int
    {
        $rows = [];

        foreach ($registry->all() as $key => $owner) {
            [$method, $uri] = explode(' ', $key, 2);
            $rows[] = [$method, '/'.$uri, $owner];
        }

        usort($rows, fn (array $a, array $b): int => [$a[1], $a[0]] <=> [$b[1], $b[0]]);

        $this->table(['Metoda', 'URI', 'Właściciel'], $rows);
        $this->info(sprintf('Łącznie tras: %d', count($rows)));

        return self::SUCCESS;
    }
}
