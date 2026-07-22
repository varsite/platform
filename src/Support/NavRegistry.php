<?php

declare(strict_types=1);

namespace Varsite\Platform\Support;

use InvalidArgumentException;

/**
 * Rejestr nawigacji panelu (F4: App Shell z REJESTRU MODUŁÓW).
 * Core i moduły dokładają pozycje w providerach; panel pobiera gotową
 * strukturę z GET /api/v1/admin/bootstrap. Ikony = nazwy lucide (kebab-case),
 * mapowane po stronie panelu. Id pozycji są unikalne (kolizja = wyjątek).
 *
 * @phpstan-type NavItem array{id:string,label:string,icon:string,href:string,order?:int,soon?:bool,count?:int|null}
 */
final class NavRegistry
{
    /** @var array<string, array{order:int, items: array<string, array{id:string,label:string,icon:string,href:string,order:int,soon:bool}>}> */
    private array $groups = [];

    /** @param array{id:string,label:string,icon:string,href:string,order?:int,soon?:bool} $item */
    public function item(string $group, array $item, int $groupOrder = 50): self
    {
        $id = $item['id'];

        foreach ($this->groups as $g) {
            if (isset($g['items'][$id])) {
                throw new InvalidArgumentException(
                    sprintf('Pozycja nawigacji "%s" jest już zarejestrowana — id musi być unikalne w całej platformie.', $id),
                );
            }
        }

        if (! isset($this->groups[$group])) {
            $this->groups[$group] = ['order' => $groupOrder, 'items' => []];
        }

        $this->groups[$group]['items'][$id] = [
            'id' => $id,
            'label' => $item['label'],
            'icon' => $item['icon'],
            'href' => $item['href'],
            'order' => $item['order'] ?? 50,
            'soon' => $item['soon'] ?? false,
        ];

        return $this;
    }

    /** @return list<array{heading:string, items: list<array{id:string,label:string,icon:string,href:string,soon:bool}>}> */
    public function toArray(): array
    {
        $groups = $this->groups;
        uasort($groups, fn (array $a, array $b): int => $a['order'] <=> $b['order']);

        $out = [];

        foreach ($groups as $heading => $group) {
            $items = array_values($group['items']);
            usort($items, fn (array $a, array $b): int => $a['order'] <=> $b['order']);

            $out[] = [
                'heading' => $heading,
                'items' => array_map(
                    static fn (array $i): array => [
                        'id' => $i['id'],
                        'label' => $i['label'],
                        'icon' => $i['icon'],
                        'href' => $i['href'],
                        'soon' => $i['soon'],
                    ],
                    $items,
                ),
            ];
        }

        return $out;
    }
}
