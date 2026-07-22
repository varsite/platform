<?php

declare(strict_types=1);

namespace Varsite\Platform\Http\Controllers;

use Illuminate\Http\Request;
use Varsite\Platform\Http\Resources\HealthResource;

/**
 * Minimalny, nie-biznesowy endpoint infrastrukturalny (Faza 0).
 * Napędza kontrakt OpenAPI i dowodzi przepływu API → SDK → frontend.
 */
final class HealthController
{
    public function __invoke(Request $request): HealthResource
    {
        return new HealthResource([
            'status' => 'ok',
            'version' => (string) config('platform.version', '0.0.0'),
            'time' => now()->toIso8601String(),
        ]);
    }
}
