<?php

declare(strict_types=1);

namespace Varsite\Platform\Contracts;

/**
 * Możliwość udostępniana przez platformę (kernel API).
 *
 * Jeden kontrakt dla WSZYSTKICH rodzajów rozszerzeń: zasobów, widgetów,
 * ustawień, stron, wyszukiwarek, komend. Dzięki temu dodanie nowego rodzaju
 * nie wymaga zmiany kontraktu bootstrapu ani kodu klientów — nieznany `kind()`
 * klient po prostu ignoruje (rozszerzalność bez breaking changes).
 *
 * Wzorzec dostępu jest jednolity dla całej platformy:
 *
 *     manifest  → deklaracja           → dane
 *     bootstrap → /admin/capabilities/{key} → endpoint modułu
 *
 * `manifest()` musi być LEKKI (kilkadziesiąt bajtów): odpowiada na pytanie
 * „co platforma potrafi". `declaration()` zawiera pełny opis i jest pobierana
 * leniwie, dopiero gdy klient realnie z możliwości korzysta.
 */
interface Capability
{
    /** Rodzaj możliwości, np. "resource", "widget", "setting". Rozszerzalny bez zmian w Core. */
    public function kind(): string;

    /** Globalnie unikalny klucz z prefiksem modułu, np. "blog.posts". */
    public function key(): string;

    /** Moduł-właściciel (wyliczany z klucza) — pozwala usunąć wszystko po odinstalowaniu. */
    public function owner(): string;

    /** Uprawnienie wymagane do zobaczenia możliwości; null = widoczna dla każdego zalogowanego. */
    public function requiredPermission(): ?string;

    /**
     * Lekki wpis manifestu (bootstrap). Bez kolumn, pól i innych szczegółów.
     *
     * @return array<string,mixed>
     */
    public function manifest(): array;

    /**
     * Pełna deklaracja pobierana leniwie przez /admin/capabilities/{key}.
     *
     * @return array<string,mixed>
     */
    public function declaration(): array;
}
