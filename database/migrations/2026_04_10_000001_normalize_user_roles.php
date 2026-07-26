<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ujednolicenie identyfikatorów ról.
 *
 * Wcześniejsze wydania dopuszczały polską nazwę roli właściciela obok
 * angielskiej. Identyfikator musi być jeden i angielski — etykieta należy
 * do warstwy prezentacji (config: platform.auth.role_labels).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'role')) {
            return;
        }

        DB::table('users')->whereIn('role', ['Właściciel', 'wlasciciel', 'Owner', 'OWNER'])->update(['role' => 'owner']);
        DB::table('users')->whereIn('role', ['Redaktor', 'Editor'])->update(['role' => 'editor']);
        DB::table('users')->whereIn('role', ['Podgląd', 'Viewer'])->update(['role' => 'viewer']);
    }

    public function down(): void
    {
        // Normalizacja identyfikatorów nie podlega cofnięciu.
    }
};
