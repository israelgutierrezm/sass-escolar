<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `personal_access_tokens` (TENANT) — los tokens de Sanctum de la app móvil.
 *
 * Va en el tenant, no en la central, porque el LOGIN es de PERSONAS y las
 * personas viven en la BD de cada escuela: el token pertenece a un usuario del
 * tenant y se resuelve con la tenencia ya inicializada. Sanctum v4 no corre su
 * migración por su cuenta (sólo la PUBLICA), así que esta es la única que crea
 * la tabla, y sólo en el tenant.
 *
 * Es una tabla de infraestructura (el modelo es de Sanctum, no nuestro), así que
 * NO lleva el macro `auditoria()`: nada escribiría `created_by`/`updated_by`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('personal_access_tokens')) {
            return;
        }

        Schema::create('personal_access_tokens', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->morphs('tokenable');
            $tabla->text('name');
            $tabla->string('token', 64)->unique();
            $tabla->text('abilities')->nullable();
            $tabla->timestamp('last_used_at')->nullable();
            $tabla->timestamp('expires_at')->nullable()->index();
            $tabla->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_access_tokens');
    }
};
