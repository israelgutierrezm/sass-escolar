<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dónde está el campus, en coordenadas.
 *
 * Para el clima del panel, y de paso para cualquier cosa que después necesite
 * ubicarlo en un mapa.
 *
 * ── Por qué el campus y no la IP de quien entra ────────────────────────────
 * Geolocalizar por IP falla justo donde más se usa el sistema: desde la red de
 * la escuela todas las peticiones salen por el mismo enlace, así que media
 * escuela vería el clima del proveedor de internet. Con VPN da cualquier cosa.
 * Y sobre todo, la IP de una persona es un dato personal: mandarla a un
 * servicio externo para adornar una tarjeta no se sostiene frente a la
 * LFPDPPP cuando el sistema YA SABE en qué campus estudia.
 *
 * Nullable porque no es obligatorio: un campus sin coordenadas simplemente no
 * muestra clima —o cae al respaldo por IP, si la escuela lo habilita—.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campus', function (Blueprint $table) {
            // 7 decimales: precisión de centímetros, de sobra para un plantel.
            $table->decimal('latitud', 10, 7)->nullable()->after('online');
            $table->decimal('longitud', 10, 7)->nullable()->after('latitud');
        });
    }

    public function down(): void
    {
        Schema::table('campus', function (Blueprint $table) {
            $table->dropColumn(['latitud', 'longitud']);
        });
    }
};
