<?php

declare(strict_types=1);

namespace Varsite\Platform\Capabilities;

use InvalidArgumentException;
use Varsite\Platform\Contracts\Capability;

/**
 * Deklaratywny zasób — możliwość typu "resource" (Warstwa 1 rozszerzalności).
 *
 * Moduł opisuje CO ma być pokazane; klient renderuje ją własnymi środkami.
 * Dzięki temu instalacja modułu nie wymaga zmian w kodzie żadnego klienta ani
 * budowania frontendu u klienta.
 *
 * Klucz MUSI mieć prefiks modułu ("blog.posts") — gwarantuje brak kolizji
 * między modułami i pozwala jednoznacznie usunąć zasoby po odinstalowaniu.
 */
final class ResourceCapability implements Capability
{
    private string $label = '';

    private string $labelPlural = '';

    private string $icon = 'box';

    private string $endpoint = '';

    private ?string $permission = null;

    private ?string $reorderEndpoint = null;

    private ?string $route = null;

    /** @var list<Column> */
    private array $columns = [];

    /** @var list<Field> */
    private array $form = [];

    /** @var list<Filter> */
    private array $filters = [];

    /** @var list<Action> */
    private array $actions = [];

    private function __construct(private readonly string $key)
    {
        if (! str_contains($key, '.')) {
            throw new InvalidArgumentException(sprintf(
                'Klucz zasobu "%s" musi zawierać prefiks modułu, np. "blog.posts".',
                $key,
            ));
        }
    }

    public static function make(string $key): self
    {
        return new self($key);
    }

    public function key(): string
    {
        return $this->key;
    }

    /** Moduł-właściciel wyliczany z klucza — Core nie musi go znać z nazwy. */
    public function owner(): string
    {
        return explode('.', $this->key, 2)[0];
    }

    /**
     * Sugerowana ścieżka otwarcia możliwości (links.open). Deterministycznie
     * z klucza ("blog.posts" → "/blog/posts"). To wskazówka nawigacyjna dla
     * klientów, które mają nawigację — nie wymóg kontraktu.
     */
    public function routePath(): string
    {
        return $this->route ?? '/'.str_replace('.', '/', $this->key);
    }

    public function route(string $path): self
    {
        $this->route = $path;

        return $this;
    }

    public function label(string $singular, string $plural): self
    {
        $this->label = $singular;
        $this->labelPlural = $plural;

        return $this;
    }

    public function icon(string $icon): self
    {
        $this->icon = $icon;

        return $this;
    }

    /** Bazowy endpoint CRUD zasobu (moduł odpowiada za jego implementację i autoryzację). */
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

    /** @param list<Column> $columns */
    public function columns(array $columns): self
    {
        $this->columns = $columns;

        return $this;
    }

    /** @param list<Field> $fields */
    public function form(array $fields): self
    {
        $this->form = $fields;

        return $this;
    }

    /** @param list<Filter> $filters */
    public function filters(array $filters): self
    {
        $this->filters = $filters;

        return $this;
    }

    /** @param list<Action> $actions */
    public function actions(array $actions): self
    {
        $this->actions = $actions;

        return $this;
    }

    public function reorderable(string $endpoint): self
    {
        $this->reorderEndpoint = $endpoint;

        return $this;
    }

    public function kind(): string
    {
        return 'resource';
    }

    public function requiredPermission(): ?string
    {
        return $this->permission;
    }

    /**
     * Lekki wpis manifestu — wyłącznie tożsamość i nawigacja.
     * Kolumny, filtry, formularz i akcje NIE trafiają do bootstrapu.
     *
     * @return array<string,mixed>
     */
    public function manifest(): array
    {
        return array_filter([
            'kind' => $this->kind(),
            'key' => $this->key,
            'owner' => $this->owner(),
            'label' => $this->label,
            'labelPlural' => $this->labelPlural,
            'icon' => $this->icon,
            'permission' => $this->permission,
            // Wyłącznie linki: nie każda możliwość jest ekranem (widget, komenda,
            // dostawca wyszukiwania czy zadanie cron nie mają ścieżki nawigacji).
            // Klient sam decyduje, którego linku użyje w swoim rendererze.
            'links' => array_filter([
                'declaration' => '/v1/admin/capabilities/'.$this->key,
                'data' => $this->endpoint,
                'open' => $this->routePath(),
                // Relacja obecna tylko wtedy, gdy zasób zadeklarował filtr
                // wyszukiwania — klient wie, gdzie szukać, bez pobierania
                // pełnych deklaracji wszystkich zasobów przy starcie.
                'search' => $this->searchable() ? $this->endpoint : null,
            ]),
        ], static fn (mixed $v): bool => $v !== null && $v !== '');
    }

    /** Czy zasób obsługuje wyszukiwanie tekstowe (zadeklarowany filtr typu search). */
    private function searchable(): bool
    {
        foreach ($this->filters as $filter) {
            if (($filter->toArray()['type'] ?? null) === 'search') {
                return true;
            }
        }

        return false;
    }

    /** @return array<string,mixed> */
    public function declaration(): array
    {
        return $this->toArray();
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return array_filter([
            'kind' => $this->kind(),
            'key' => $this->key,
            'owner' => $this->owner(),
            'label' => $this->label,
            'labelPlural' => $this->labelPlural,
            'icon' => $this->icon,
            'endpoint' => $this->endpoint,
            'permission' => $this->permission,
            'reorderEndpoint' => $this->reorderEndpoint,
            'columns' => array_map(static fn (Column $c): array => $c->toArray(), $this->columns),
            'filters' => array_map(static fn (Filter $f): array => $f->toArray(), $this->filters),
            'form' => array_map(static fn (Field $f): array => $f->toArray(), $this->form),
            'actions' => array_map(static fn (Action $a): array => $a->toArray(), $this->actions),
        ], static fn (mixed $v): bool => $v !== null && $v !== [] && $v !== '');
    }
}
