<?php

declare(strict_types=1);

namespace Varsite\Platform\Capabilities;

/** Filtr listy zasobu. Klient wysyła wybraną wartość jako parametr zapytania do endpointu modułu. */
final class Filter
{
    /** @param array<string,mixed> $options */
    private function __construct(
        private readonly string $type,
        private readonly string $key,
        private string $label = '',
        private array $options = [],
    ) {}

    /** @param list<string> $keys pola przeszukiwane po stronie serwera (parametr `q`) */
    public static function search(array $keys = []): self
    {
        return new self('search', 'q', options: $keys === [] ? [] : ['keys' => $keys]);
    }

    /** @param array<int|string,string>|string $options statyczne albo endpoint z {data:[{id,name}]} */
    public static function select(string $key, array|string $options): self
    {
        return new self('select', $key, options: is_string($options)
            ? ['optionsEndpoint' => $options]
            : ['options' => $options]);
    }

    /** @param array<string,string> $options przełącznik segmentowy (małe zbiory wartości) */
    public static function segmented(string $key, array $options): self
    {
        return new self('segmented', $key, options: ['options' => $options]);
    }

    public function label(string $label): self
    {
        $this->label = $label;

        return $this;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return array_filter([
            'type' => $this->type,
            'key' => $this->key,
            'label' => $this->label,
            ...$this->options,
        ], static fn (mixed $v): bool => $v !== null && $v !== '');
    }
}
