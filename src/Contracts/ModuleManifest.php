<?php

declare(strict_types=1);

namespace Varsite\Platform\Contracts;

/**
 * Tożsamość modułu — deklaracja kodu leżącego w pakiecie.
 *
 * Zasady (docs/DESIGN-MODULE-MANIFEST.md):
 *
 *  M1. NIEZMIENNY. Zero setterów, zero builderów, zero uzupełniania po
 *      utworzeniu. Provider zwraca gotowy obiekt i na tym kończy swoją rolę.
 *
 *  M2. NIEZALEŻNY OD ŚRODOWISKA. Zwraca identyczne dane niezależnie od
 *      konfiguracji, użytkownika, tenanta i bazy. W konstruktorze nie wolno
 *      wywołać config(), env(), auth() ani zapytać bazy.
 *
 *      Test rozstrzygający: jeśli wartość mogłaby się różnić między dwiema
 *      instalacjami TEGO SAMEGO pakietu — należy do stanu uruchomieniowego
 *      (ModuleRuntime), nie do manifestu.
 *
 * Manifest jest JEDYNYM źródłem metadanych o module dla platformy. Panel nie
 * czyta composer.json ani README — te mają innych właścicieli (Composer, człowiek).
 */
final readonly class ModuleManifest
{
    /**
     * @param string $key techniczny identyfikator, prefiks wszystkich kluczy modułu
     * @param string $name nazwa widoczna dla użytkownika
     * @param string $version wersja kodu w pakiecie
     * @param string $section identyfikator obszaru nawigacji; nieznany degraduje do domyślnego
     * @param list<string> $permissions identyfikatory uprawnień (nieprzezroczyste dla rdzenia)
     * @param string|null $requiresGeneration zgodność z generacją rdzenia, np. "^0.6"
     */
    public function __construct(
        public string $key,
        public string $name,
        public string $version,
        public ?string $description = null,
        public ?string $author = null,
        public ?string $homepage = null,
        public string $section = 'other',
        public ?string $icon = null,
        public int $order = 100,
        public array $permissions = [],
        public ?string $requiresGeneration = null,
        // Miejsce na metadane dystrybucyjne (licencja, dostawca, wsparcie,
        // dokumentacja, repozytorium) — dojdą jako pola, gdy pojawi się
        // marketplace. Architektura tego nie zamyka.
    ) {}

    /** @return array<string, mixed> reprezentacja dla klientów platformy */
    public function toArray(): array
    {
        return array_filter([
            'key' => $this->key,
            'name' => $this->name,
            'version' => $this->version,
            'description' => $this->description,
            'author' => $this->author,
            'homepage' => $this->homepage,
            'section' => $this->section,
            'icon' => $this->icon,
            'order' => $this->order,
            'permissions' => $this->permissions,
            'requiresGeneration' => $this->requiresGeneration,
        ], static fn (mixed $v): bool => $v !== null && $v !== []);
    }
}
