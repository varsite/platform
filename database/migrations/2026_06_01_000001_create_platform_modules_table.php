<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inwentarz modułów — stan instalacji, nie konfiguracja.
 *
 * Tabela odpowiada wyłącznie na pytanie „jaki jest stan tego modułu w TEJ
 * instalacji". Nazwa, opis, ikona i sekcja pochodzą z manifestu i nie są tu
 * kopiowane: kopia rozjechałaby się przy aktualizacji pakietu (N14).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_modules', function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->string('status')->default('installed')->index();

            // Wersja zapisana przy instalacji. Różnica względem wersji
            // z manifestu oznacza, że moduł wymaga aktualizacji.
            $table->string('installed_version')->nullable();
            $table->string('generation')->nullable();

            $table->timestamp('installed_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('deactivated_at')->nullable();

            $table->text('status_message')->nullable();
            $table->json('diagnostics')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_modules');
    }
};
