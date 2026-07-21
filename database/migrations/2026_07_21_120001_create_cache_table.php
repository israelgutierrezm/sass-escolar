<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Caché de la BD central (LANDLORD).
 *
 * La landlord también es una aplicación real —el panel de super admins— y con
 * CACHE_STORE=database necesita su propia tabla de caché. Sin ella, cualquier
 * operación que toque el caché en contexto central falla; en concreto
 * spatie/laravel-permission cachea su tabla de permisos y revienta con
 * "Table 'acadion_landlord.cache' doesn't exist".
 *
 * Cada tenant tiene su propia `cache` en su BD (ver migrations/tenant), así que
 * los cachés quedan aislados por escuela.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cache', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->mediumText('value');
            $table->integer('expiration')->index();
        });

        Schema::create('cache_locks', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->string('owner');
            $table->integer('expiration')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cache');
        Schema::dropIfExists('cache_locks');
    }
};
