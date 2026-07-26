<?php

declare(strict_types=1);

namespace Varsite\Platform\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Varsite\Platform\Capabilities\CapabilityRegistry;
use Varsite\Platform\Capabilities\SettingCapability;

/**
 * Przechowywanie ustawień platformy.
 *
 * Jedna grupa = jeden wiersz z ładunkiem JSON, dzięki czemu odczyt ustawień
 * modułu to pojedyncze zapytanie niezależnie od liczby pól. Wartości są
 * cache'owane per grupa i unieważniane przy zapisie — przy kilkudziesięciu
 * modułach nie generuje to ruchu do bazy na każde żądanie.
 *
 * Core nie zna znaczenia żadnego klucza: przechowuje to, co moduł zadeklarował,
 * i scala z wartościami domyślnymi z jego deklaracji.
 */
final class Settings
{
    private const TABLE = 'platform_settings';

    private const CACHE_PREFIX = 'varsite.settings.';

    public function __construct(private readonly CapabilityRegistry $capabilities) {}

    /**
     * Wartości grupy: zapisane nadpisują domyślne z deklaracji modułu.
     *
     * @return array<string, mixed>
     */
    public function all(string $key): array
    {
        $capability = $this->capabilities->get($key);
        $defaults = $capability instanceof SettingCapability ? $capability->defaultValues() : [];

        return array_merge($defaults, $this->stored($key));
    }

    public function get(string $key, string $field, mixed $fallback = null): mixed
    {
        return $this->all($key)[$field] ?? $fallback;
    }

    /** @param array<string, mixed> $values */
    public function save(string $key, array $values): void
    {
        DB::table(self::TABLE)->updateOrInsert(
            ['key' => $key],
            ['payload' => json_encode($values, JSON_UNESCAPED_UNICODE), 'updated_at' => now()],
        );

        Cache::forget(self::CACHE_PREFIX.$key);
    }

    /** @return array<string, mixed> */
    private function stored(string $key): array
    {
        /** @var array<string, mixed> $values */
        $values = Cache::rememberForever(self::CACHE_PREFIX.$key, static function () use ($key): array {
            $row = DB::table(self::TABLE)->where('key', $key)->value('payload');

            return is_string($row) ? (array) json_decode($row, true) : [];
        });

        return $values;
    }
}
