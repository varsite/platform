<?php

declare(strict_types=1);

namespace Varsite\Platform\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read array{status: string, version: string, time: string} $resource
 */
final class HealthResource extends JsonResource
{
    /** @return array{status: string, version: string, time: string} */
    public function toArray(Request $request): array
    {
        return [
            'status' => $this->resource['status'],
            'version' => $this->resource['version'],
            'time' => $this->resource['time'],
        ];
    }
}
