<?php

declare(strict_types=1);

namespace Varsite\Platform\Capabilities;

use InvalidArgumentException;
use Varsite\Platform\Contracts\Capability;

/**
 * Rejestr możliwości platformy — jedyny punkt rejestracji dla wszystkich
 * rodzajów rozszerzeń (zasoby, widgety, ustawienia, strony, wyszukiwarki…).
 *
 * Core nie wie, jakie możliwości istnieją ani jakich są rodzajów; zna wyłącznie
 * kontrakt Capability. Nowy rodzaj rozszerzenia = nowa implementacja w module,
 * zero zmian tutaj i zero zmian w kontrakcie bootstrapu.
 */
final class CapabilityRegistry
{
    /** @var array<string, Capability> */
    private array $items = [];

    public function register(Capability $capability): self
    {
        $key = $capability->key();

        if (isset($this->items[$key])) {
            throw new InvalidArgumentException(sprintf(
                'Możliwość "%s" jest już zarejestrowana (właściciel: %s) — klucze muszą być unikalne w całej platformie.',
                $key,
                $this->items[$key]->owner(),
            ));
        }

        // N11: rejestr przechowuje własną kopię. Mutacja obiektu po rejestracji
        // (np. dopisanie kolumn przez inny moduł) nie wpływa na stan platformy.
        $this->items[$key] = clone $capability;

        return $this;
    }

    public function get(string $key): ?Capability
    {
        $item = $this->items[$key] ?? null;

        return $item === null ? null : clone $item;
    }

    /**
     * N12: kolejność deterministyczna (po kluczu), niezależna od kolejności
     * bootowania providerów. Ten sam zestaw modułów zawsze daje ten sam manifest.
     *
     * @return array<string, Capability>
     */
    public function all(): array
    {
        $items = $this->items;
        ksort($items);

        return $items;
    }

    /**
     * Manifest widoczny dla użytkownika o danych uprawnieniach.
     * Filtrowanie po uprawnieniach odbywa się na poziomie LEKKIEGO manifestu —
     * nie ma potrzeby budowania pełnych deklaracji, których klient nie zobaczy.
     *
     * @param list<string> $permissions
     * @return list<array<string,mixed>>
     */
    public function manifest(array $permissions): array
    {
        return array_values(array_map(
            static fn (Capability $c): array => $c->manifest(),
            $this->visibleTo($permissions),
        ));
    }

    /**
     * @param list<string> $permissions
     * @return array<string, Capability>
     */
    public function visibleTo(array $permissions): array
    {
        $wildcard = in_array('*', $permissions, true);

        return array_filter($this->all(), static function (Capability $c) use ($permissions, $wildcard): bool {
            $required = $c->requiredPermission();

            return $required === null || $wildcard || in_array($required, $permissions, true);
        });
    }

    /**
     * Odcisk stanu rejestru — podstawa ETag. Zmienia się przy instalacji,
     * usunięciu lub aktualizacji dowolnego modułu (klucze + treść manifestów),
     * co automatycznie unieważnia cache klientów bez ręcznej inwalidacji.
     */
    public function fingerprint(): string
    {
        $keys = array_keys($this->items);
        sort($keys);

        return hash('xxh128', implode('|', $keys));
    }
}
