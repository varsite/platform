<?php

declare(strict_types=1);

namespace Varsite\Platform\Capabilities;

use InvalidArgumentException;
use Varsite\Platform\Contracts\Capability;

/**
 * Możliwość typu "setting" — grupa ustawień deklarowana przez moduł.
 *
 * Moduł opisuje CO da się ustawić (pola, wartości domyślne, walidacja,
 * uprawnienie); Core zajmuje się przechowywaniem, odczytem i zapisem. Dodanie
 * ustawienia sprowadza się do deklaracji w module — Core i panel pozostają
 * nietknięte.
 *
 * Granulacja: jedna możliwość = jedna grupa ustawień = JEDEN wiersz w bazie.
 * Przy kilkudziesięciu modułach i setkach pól odczyt pozostaje pojedynczym
 * zapytaniem na grupę, a nie zapytaniem na pole.
 *
 * Typy pól są wspólne z formularzami zasobów (klasa Field) — nowy typ dodany
 * dla zasobów działa w ustawieniach bez żadnej pracy i odwrotnie.
 */
final class SettingCapability implements Capability
{
    private string $label = '';

    private ?string $description = null;

    private string $icon = 'settings';

    private ?string $permission = null;

    private int $order = 50;

    /** @var list<Field> */
    private array $fields = [];

    /** @var array<string, mixed> */
    private array $defaults = [];

    /** @var array<string, list<string>|string> */
    private array $rules = [];

    private function __construct(private readonly string $key)
    {
        if (! str_contains($key, '.')) {
            throw new InvalidArgumentException(sprintf(
                'Klucz ustawień "%s" musi zawierać prefiks modułu, np. "blog.publikacja".',
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
        return 'setting';
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

    public function permission(string $permission): self
    {
        $this->permission = $permission;

        return $this;
    }

    public function order(int $order): self
    {
        $this->order = $order;

        return $this;
    }

    /** @param list<Field> $fields */
    public function fields(array $fields): self
    {
        $this->fields = $fields;

        return $this;
    }

    /**
     * Wartości używane, dopóki administrator niczego nie zapisał.
     *
     * @param array<string, mixed> $defaults
     */
    public function defaults(array $defaults): self
    {
        $this->defaults = $defaults;

        return $this;
    }

    /**
     * Reguły walidacji Laravela dla pól grupy. Walidację wykonuje Core, ale
     * jej treść należy do modułu — Core nie zna znaczenia żadnego pola.
     *
     * @param array<string, list<string>|string> $rules
     */
    public function rules(array $rules): self
    {
        $this->rules = $rules;

        return $this;
    }

    /** @return array<string, mixed> */
    public function defaultValues(): array
    {
        return $this->defaults;
    }

    /** @return array<string, list<string>|string> */
    public function validationRules(): array
    {
        return $this->rules;
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
            'links' => [
                'declaration' => '/v1/admin/capabilities/'.$this->key,
                'data' => '/v1/admin/settings/'.$this->key,
            ],
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
            'order' => $this->order,
            'permission' => $this->permission,
            'endpoint' => '/v1/admin/settings/'.$this->key,
            'fields' => array_map(static fn (Field $f): array => $f->toArray(), $this->fields),
        ], static fn (mixed $v): bool => $v !== null && $v !== '' && $v !== []);
    }
}
