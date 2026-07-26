<?php

declare(strict_types=1);

namespace Varsite\Platform\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Konto panelu w kontrakcie API. Hasło nigdy nie opuszcza serwera. */
final class UserResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->getKey(),
            'name' => $this->resource->name,
            'email' => $this->resource->email,
            'role' => $this->resource->role,
            'created_at' => $this->resource->created_at?->toIso8601String(),
        ];
    }
}
