<?php

declare(strict_types=1);

namespace Varsite\Platform\Enums;

/**
 * Cykl życia modułu w instalacji.
 *
 * Enum zna komplet stanów, ale logika przejść obsługuje dziś wyłącznie
 * Installed → Active ⇄ Disabled. Pozostałe są zapisane, żeby ich wprowadzenie
 * nie wymagało migracji ani zmiany kontraktu (JIT).
 */
enum ModuleStatus: string
{
    case Installed = 'installed';           // wykryty, jeszcze nieaktywowany
    case Active = 'active';                 // działa
    case Disabled = 'disabled';             // wyłączony świadomie
    case NeedsMigration = 'needs_migration'; // kod nowszy niż schemat bazy
    case RequiresUpdate = 'requires_update'; // manifest ≠ zapisana wersja
    case Incompatible = 'incompatible';      // generacja rdzenia się nie zgadza
    case Broken = 'broken';                  // rejestracja rzuciła wyjątkiem

    /** Czy moduł ma rejestrować swoje możliwości. */
    public function isActive(): bool
    {
        return $this === self::Active || $this === self::Installed;
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return [
            self::Installed->value => 'Zainstalowany',
            self::Active->value => 'Aktywny',
            self::Disabled->value => 'Wyłączony',
            self::NeedsMigration->value => 'Wymaga migracji',
            self::RequiresUpdate->value => 'Wymaga aktualizacji',
            self::Incompatible->value => 'Niezgodny',
            self::Broken->value => 'Uszkodzony',
        ];
    }

    /** @return array<string, array{tone: string, label: string}> */
    public static function tones(): array
    {
        return [
            self::Active->value => ['tone' => 'ok', 'label' => 'Aktywny'],
            self::Installed->value => ['tone' => 'ok', 'label' => 'Zainstalowany'],
            self::Disabled->value => ['tone' => 'muted', 'label' => 'Wyłączony'],
            self::NeedsMigration->value => ['tone' => 'warn', 'label' => 'Wymaga migracji'],
            self::RequiresUpdate->value => ['tone' => 'warn', 'label' => 'Wymaga aktualizacji'],
            self::Incompatible->value => ['tone' => 'danger', 'label' => 'Niezgodny'],
            self::Broken->value => ['tone' => 'danger', 'label' => 'Uszkodzony'],
        ];
    }
}
