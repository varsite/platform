<?php

declare(strict_types=1);

namespace Varsite\Platform\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Serwowanie frontendów przez Core (Core-first routing).
 *
 * PANEL: assets podróżują W PAKIECIE (model Filament) i są serwowane trasami
 * Laravela — instalacja i aktualizacja = composer, zero kroków publikacji.
 * Opcjonalnie: `vendor:publish --tag=platform-admin-assets` wystawia pliki do
 * public/admin, wtedy serwer WWW poda je bezpośrednio (optymalizacja prod);
 * deep-linki i tak obsługuje ta klasa.
 *
 * STRONA: buduje ją aplikacja-host (własny frontend klienta) do public/.
 */
final class FrontendController
{
    private const ASSET_TYPES = [
        'js' => 'text/javascript; charset=utf-8',
        'css' => 'text/css; charset=utf-8',
        'map' => 'application/json',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'ico' => 'image/x-icon',
        'woff2' => 'font/woff2',
        'txt' => 'text/plain; charset=utf-8',
    ];

    public function website(): BinaryFileResponse|Response
    {
        return $this->html(config('platform.frontends.website').'/index.html');
    }

    public function admin(): BinaryFileResponse|Response
    {
        return $this->html($this->adminDist('index.html'));
    }

    /** Runtime-konfiguracja panelu: override wdrożeniowy (public/admin/config.js) albo domyślna z pakietu. */
    public function adminConfig(): BinaryFileResponse|Response
    {
        $override = public_path('admin/config.js');
        $path = is_file($override) ? $override : $this->adminDist('config.js');

        return $this->file($path, 'text/javascript; charset=utf-8', 'no-cache, must-revalidate');
    }

    /** Statyczne assety panelu z pakietu (fingerprintowane → cache immutable). */
    public function adminAsset(string $path): BinaryFileResponse|Response
    {
        $base = realpath($this->adminDist('assets'));
        $target = realpath($this->adminDist('assets/'.$path));

        if ($base === false || $target === false || ! str_starts_with($target, $base.DIRECTORY_SEPARATOR)) {
            abort(404);
        }

        $extension = strtolower(pathinfo($target, PATHINFO_EXTENSION));
        $mime = self::ASSET_TYPES[$extension] ?? null;

        if ($mime === null) {
            abort(404);
        }

        return $this->file($target, $mime, 'public, max-age=31536000, immutable');
    }

    public function fallback(Request $request): BinaryFileResponse|Response|JsonResponse
    {
        if ($request->is('api/*')) {
            return response()->json(['message' => 'Not Found.'], 404);
        }

        $notFound = config('platform.frontends.website').'/404.html';

        if (is_file($notFound)) {
            return response()->file($notFound, ['Content-Type' => 'text/html'])->setStatusCode(404);
        }

        return $this->html(config('platform.frontends.website').'/index.html');
    }

    /** Ścieżka w dystrybucji panelu: wdrożeniowa publikacja ma pierwszeństwo, inaczej pakiet. */
    private function adminDist(string $file): string
    {
        $published = config('platform.frontends.admin');

        if (is_string($published) && $published !== '' && is_file(rtrim($published, '/').'/index.html')) {
            return rtrim($published, '/').'/'.$file;
        }

        return dirname(__DIR__, 3).'/resources/dist/admin/'.$file;
    }

    private function html(string $path): BinaryFileResponse|Response
    {
        if (! is_file($path)) {
            return response(
                'Frontend nie jest zbudowany. Uruchom build-release.sh (artefakt zawiera gotowe frontendy).',
                404,
            )->header('Content-Type', 'text/plain; charset=utf-8');
        }

        return $this->file($path, 'text/html', 'no-cache, must-revalidate');
    }

    private function file(string $path, string $mime, string $cache): BinaryFileResponse|Response
    {
        if (! is_file($path)) {
            abort(404);
        }

        return response()->file($path, ['Content-Type' => $mime, 'Cache-Control' => $cache]);
    }
}
