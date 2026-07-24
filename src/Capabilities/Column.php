<?php

declare(strict_types=1);

namespace Varsite\Platform\Capabilities;

/**
 * Kolumna tabeli zasobu. Deklaracja przesyłana klientowi —
 * klient renderuje ją własnym komponentem. Typy dodajemy przyrostowo (JIT),
 * gdy moduł realnie ich potrzebuje; nieznany typ panel renderuje jako tekst.
 */
final class Column
{
    /** @param array<string,mixed> $options */
    private function __construct(
        private readonly string $type,
        private readonly string $key,
        private string $label = '',
        private bool $sortable = false,
        private bool $primary = false,
        private array $options = [],
    ) {}

    public static function text(string $key): self
    {
        return new self('text', $key);
    }

    public static function badge(string $key): self
    {
        return new self('badge', $key);
    }

    /** @param array<string,array{tone:string,label:string}> $map */
    public static function status(string $key, array $map): self
    {
        return new self('status', $key, options: ['map' => $map]);
    }

    /** Sekundy → format mm:ss po stronie panelu. */
    public static function duration(string $key): self
    {
        return new self('duration', $key);
    }

    public static function date(string $key): self
    {
        return new self('date', $key);
    }

    public static function number(string $key): self
    {
        return new self('number', $key);
    }

    public function label(string $label): self
    {
        $this->label = $label;

        return $this;
    }

    public function sortable(bool $sortable = true): self
    {
        $this->sortable = $sortable;

        return $this;
    }

    /** Kolumna wiodąca — panel renderuje ją jako klikalny tytuł wiersza. */
    public function primary(bool $primary = true): self
    {
        $this->primary = $primary;

        return $this;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return array_filter([
            'type' => $this->type,
            'key' => $this->key,
            'label' => $this->label !== '' ? $this->label : $this->key,
            'sortable' => $this->sortable,
            'primary' => $this->primary,
            ...$this->options,
        ], static fn (mixed $v): bool => $v !== false && $v !== null);
    }
}
