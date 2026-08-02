<?php

declare(strict_types=1);

namespace Varsite\Platform\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Obecność tabel platformy — fakt stały dla działającej instalacji.
 *
 * `Schema::hasTable()` na MySQL to zapytanie do `information_schema`, które
 * przy obciążeniu jest jednym z droższych. Rdzeń pytał o to dwukrotnie
 * w KAŻDYM żądaniu HTTP (inwentarz modułów i ustawienia), mimo że odpowiedź
 * zmienia się wyłącznie przy migracji.
 *
 * Wynik trafia do cache i jest unieważniany przez `varsite:update` oraz
 * `varsite:install`. Bufor w pamięci chroni dodatkowo przed powtórnym
 * odczytem w obrębie jednego żądania.
 */
final class InstallationState
{
    private const CACHE_KEY = 'varsite.installation.tables';

    /** @var array<string, bool> */
    private static array $memo = [];

    /** Czy tabela platformy istnieje. */
    public static function hasTable(string $table): bool
    {
        if (isset(self::$memo[$table])) {
            return self::$memo[$table];
        }

        try {
            /** @var array<string, bool> $known */
            $known = Cache::get(self::CACHE_KEY, []);

            if (array_key_exists($table, $known)) {
                return self::$memo[$table] = $known[$table];
            }

            $exists = Schema::hasTable($table);

            // Zapisujemy wyłącznie odpowiedź twierdzącą: brak tabeli oznacza
            // instalację w toku, a wtedy cache utrwaliłby stan przejściowy.
            if ($exists) {
                Cache::forever(self::CACHE_KEY, [...$known, $table => true]);
            }

            return self::$memo[$table] = $exists;
        } catch (Throwable) {
            return self::$memo[$table] = false;
        }
    }

    /** Czyści wiedzę o schemacie — po migracjach i instalacji. */
    public static function forget(): void
    {
        self::$memo = [];

        try {
            Cache::forget(self::CACHE_KEY);
        } catch (Throwable) {
            // Brak cache nie jest błędem — następne pytanie sprawdzi schemat.
        }
    }
}
