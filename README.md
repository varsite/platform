# varsite/platform

Rdzeń Varsite Platform — mechanizm rozszerzania, panel administracyjny i API.

```bash
composer require varsite/platform
php artisan varsite:install
```

## Co zawiera

| Element | Rola |
|---|---|
| `Capability` + `CapabilityRegistry` | jeden kontrakt dla wszystkich rodzajów rozszerzeń |
| `ModuleRouteRegistrar` | rejestracja tras z wykrywaniem kolizji |
| `Rbac` | rozstrzyganie uprawnień: użytkownik → rola → uprawnienie |
| `Settings` | przechowywanie ustawień deklarowanych przez moduły |
| Panel administracyjny | zbudowany, dostarczany w pakiecie (`resources/dist/admin`) |
| Komendy `varsite:*` | `install`, `update`, `module`, `admin`, `doctor`, `routes` |

## Dokumentacja

- **[EXTENSIBILITY](../../docs/EXTENSIBILITY.md)** — jak pisać moduły
- **[INVARIANTS](../../docs/INVARIANTS.md)** — niezmienniki architektury (N1–N14)
- **[DEPENDENCY-STRATEGY](../../docs/DEPENDENCY-STRATEGY.md)** — gdzie mieszka kontrakt
- **[DEVOPS](../../docs/DEVOPS.md)** — wydanie, instalacja, aktualizacja

## Testy

```bash
composer install && composer test
```

Suita używa Orchestra Testbench — framework testuje się sam, bez aplikacji
klienckiej w repozytorium.
