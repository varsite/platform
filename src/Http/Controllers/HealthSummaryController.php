<?php

declare(strict_types=1);

namespace Varsite\Platform\Http\Controllers;

use Composer\InstalledVersions;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;
use Varsite\Platform\Support\ModuleManager;

/**
 * Dane widgetu stanu platformy — skrócona wersja diagnostyki `varsite:doctor`
 * przeznaczona do podglądu w interfejsie.
 *
 * Zwraca werdykt oraz kluczowe parametry środowiska. Pełną analizę z opisem
 * napraw daje komenda; tutaj chodzi o szybki sygnał „czy coś wymaga uwagi".
 */
final class HealthSummaryController
{
    public function __invoke(ModuleManager $modules): JsonResponse
    {
        $issues = [];
        $rows = [];

        $rows[] = ['label' => 'Platforma', 'value' => $this->platformVersion()];
        $rows[] = ['label' => 'PHP', 'value' => PHP_VERSION];
        $rows[] = ['label' => 'Środowisko', 'value' => app()->environment()];

        if (app()->environment('production') && (bool) config('app.debug')) {
            $issues[] = 'Tryb debugowania aktywny w produkcji';
        }

        try {
            DB::connection()->getPdo();
            $driver = DB::connection()->getDriverName();
            $rows[] = ['label' => 'Baza danych', 'value' => $driver, 'tone' => 'ok'];

            if (Schema::hasTable('migrations')) {
                $rows[] = ['label' => 'Migracje', 'value' => (string) DB::table('migrations')->count()];
            }
        } catch (Throwable) {
            $rows[] = ['label' => 'Baza danych', 'value' => 'brak połączenia', 'tone' => 'danger'];
            $issues[] = 'Brak połączenia z bazą danych';
        }

        $rows[] = ['label' => 'Cache', 'value' => (string) config('cache.default')];

        $queue = (string) config('queue.default');
        $rows[] = ['label' => 'Kolejki', 'value' => $queue, 'tone' => $queue === 'sync' ? 'warn' : null];

        $mailer = (string) config('mail.default');
        if (in_array($mailer, ['log', 'array'], true)) {
            $issues[] = 'Poczta w trybie "'.$mailer.'" — wiadomości nie są wysyłane';
        }
        $rows[] = ['label' => 'Poczta', 'value' => $mailer];

        $keys = $modules->ids();
        $rows[] = ['label' => 'Moduły', 'value' => $keys === [] ? 'brak' : implode(', ', $keys)];

        if (! is_writable(storage_path())) {
            $issues[] = 'Katalog storage bez prawa zapisu';
        }

        return response()->json(['data' => [
            'status' => $issues === [] ? 'ok' : 'warn',
            'summary' => $issues === []
                ? 'Platforma działa poprawnie'
                : sprintf('%d %s wymaga uwagi', count($issues), count($issues) === 1 ? 'element' : 'elementy'),
            'issues' => $issues,
            'rows' => array_values(array_filter($rows)),
        ]]);
    }

    private function platformVersion(): string
    {
        try {
            return InstalledVersions::getPrettyVersion('varsite/platform') ?? 'dev';
        } catch (Throwable) {
            return 'dev';
        }
    }
}
