<?php

declare(strict_types=1);

namespace Varsite\Platform\Capabilities;

use InvalidArgumentException;
use Varsite\Platform\Contracts\Capability;

/**
 * Możliwość typu "widget" — zwięzła jednostka informacji dostarczana przez moduł.
 *
 * Widget NIE należy do żadnego ekranu. Ten sam widget może zostać wyrenderowany
 * na pulpicie administratora, ekranie głównym aplikacji mobilnej, w panelu
 * bocznym, na ekranie operatora czy w kiosku — o miejscu i sposobie decyduje
 * wyłącznie klient (N10).
 *
 * Zgodnie z N1 manifest mówi wyłącznie „co istnieje"; wariant, rozmiar
 * i kolejność to szczegóły prezentacji i żyją w deklaracji.
 *
 * Kontrakt danych (endpoint modułu) zależy od wariantu:
 *   stat  → { "data": { "value": 42, "delta": 12.5, "hint": "…" } }
 *   chart → { "data": { "series": [ { "x": "2026-01", "y": 120 } ] } }
 *   list  → { "data": [ { "title": "…", "meta": "…", "href": "…" } ] }
 */
final class WidgetCapability implements Capability
{
    public const VARIANT_STAT = 'stat';

    public const VARIANT_CHART = 'chart';

    public const VARIANT_LIST = 'list';

    public const SIZE_QUARTER = 'quarter';

    public const SIZE_THIRD = 'third';

    public const SIZE_HALF = 'half';

    public const SIZE_FULL = 'full';

    private string $label = '';

    private ?string $description = null;

    private string $icon = 'box';

    private string $variant = self::VARIANT_STAT;

    private string $size = self::SIZE_QUARTER;

    private int $order = 50;

    private string $endpoint = '';

    private ?string $permission = null;

    private ?int $refreshSeconds = null;

    private ?string $link = null;

    private function __construct(private readonly string $key)
    {
        if (! str_contains($key, '.')) {
            throw new InvalidArgumentException(sprintf(
                'Klucz widgetu "%s" musi zawierać prefiks modułu, np. "blog.published".',
                $key,
            ));
        }
    }

    public static function make(string $key): self
    {
        return new self($key);
    }

    public function kind(): string
    {
        return 'widget';
    }

    public function key(): string
    {
        return $this->key;
    }

    public function owner(): string
    {
        return explode('.', $this->key, 2)[0];
    }

    public function requiredPermission(): ?string
    {
        return $this->permission;
    }

    public function label(string $label): self
    {
        $this->label = $label;

        return $this;
    }

    public function description(string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function icon(string $icon): self
    {
        $this->icon = $icon;

        return $this;
    }

    /** Wariant prezentacji: stat, chart lub list (rozszerzalny — klient ignoruje nieznane). */
    public function variant(string $variant): self
    {
        $this->variant = $variant;

        return $this;
    }

    /** Sugerowany udział w siatce; klient bez siatki może to zignorować. */
    public function size(string $size): self
    {
        $this->size = $size;

        return $this;
    }

    public function order(int $order): self
    {
        $this->order = $order;

        return $this;
    }

    /** Endpoint danych widgetu (moduł odpowiada za jego implementację i autoryzację). */
    public function endpoint(string $endpoint): self
    {
        $this->endpoint = $endpoint;

        return $this;
    }

    public function permission(string $permission): self
    {
        $this->permission = $permission;

        return $this;
    }

    /** Sugerowane odświeżanie danych; null = tylko przy wejściu na ekran. */
    public function refresh(int $seconds): self
    {
        $this->refreshSeconds = $seconds;

        return $this;
    }

    /** Relacja "open" — dokąd prowadzi kliknięcie widgetu (opcjonalna). */
    public function opensAt(string $path): self
    {
        $this->link = $path;

        return $this;
    }

    /** @return array<string,mixed> */
    public function manifest(): array
    {
        return array_filter([
            'kind' => $this->kind(),
            'key' => $this->key,
            'owner' => $this->owner(),
            'label' => $this->label,
            'icon' => $this->icon,
            'permission' => $this->permission,
            'links' => array_filter([
                'declaration' => '/v1/admin/capabilities/'.$this->key,
                'data' => $this->endpoint,
                'open' => $this->link,
            ]),
        ], static fn (mixed $v): bool => $v !== null && $v !== '');
    }

    /** @return array<string,mixed> */
    public function declaration(): array
    {
        return array_filter([
            'kind' => $this->kind(),
            'key' => $this->key,
            'owner' => $this->owner(),
            'label' => $this->label,
            'description' => $this->description,
            'icon' => $this->icon,
            'variant' => $this->variant,
            'size' => $this->size,
            'order' => $this->order,
            'refresh' => $this->refreshSeconds,
            'endpoint' => $this->endpoint,
            'permission' => $this->permission,
        ], static fn (mixed $v): bool => $v !== null && $v !== '');
    }
}
