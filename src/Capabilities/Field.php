<?php

declare(strict_types=1);

namespace Varsite\Platform\Capabilities;

/**
 * Pole formularza zasobu. Walidacja pozostaje po stronie modułu (FormRequest) —
 * tu deklarujemy wyłącznie prezentację. `required` służy podpowiedzi w UI,
 * nie zastępuje walidacji serwerowej.
 */
final class Field
{
    /** @param array<string,mixed> $options */
    private function __construct(
        private readonly string $type,
        private readonly string $key,
        private string $label = '',
        private bool $required = false,
        private ?string $hint = null,
        private array $options = [],
    ) {}

    public static function text(string $key): self
    {
        return new self('text', $key);
    }

    public static function textarea(string $key): self
    {
        return new self('textarea', $key);
    }

    public static function number(string $key): self
    {
        return new self('number', $key);
    }

    public static function toggle(string $key): self
    {
        return new self('toggle', $key);
    }

    /**
     * Lista wyboru. Opcje statyczne albo endpoint zwracający {data:[{id,name}]}.
     *
     * @param array<int|string,string>|string $options
     */
    public static function select(string $key, array|string $options): self
    {
        return new self('select', $key, options: is_string($options)
            ? ['optionsEndpoint' => $options]
            : ['options' => $options]);
    }

    /**
     * Referencja do rekordu innego zasobu (dowolnego modułu). Core nie zna
     * semantyki celu — zna wyłącznie endpoint, z którego panel pobierze opcje.
     * Opcjonalne parametry zawężają listę (np. ['type' => 'image']).
     *
     * @param array<string,string> $params
     */
    public static function reference(string $key, string $endpoint, array $params = []): self
    {
        return new self('reference', $key, options: array_filter([
            'optionsEndpoint' => $endpoint,
            'params' => $params === [] ? null : $params,
        ]));
    }

    public function label(string $label): self
    {
        $this->label = $label;

        return $this;
    }

    public function required(bool $required = true): self
    {
        $this->required = $required;

        return $this;
    }

    public function hint(string $hint): self
    {
        $this->hint = $hint;

        return $this;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return array_filter([
            'type' => $this->type,
            'key' => $this->key,
            'label' => $this->label !== '' ? $this->label : $this->key,
            'required' => $this->required,
            'hint' => $this->hint,
            ...$this->options,
        ], static fn (mixed $v): bool => $v !== false && $v !== null);
    }
}
