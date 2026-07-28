<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La API de Organizaciones y de subida de CSD de Facturapi (crear organización,
 * subir el .cer/.key, pedir la llave de pruebas) exige la SECRET ADMIN KEY de la
 * cuenta (`sk_user_...`), distinta de las llaves de facturación por organización
 * (`sk_test_`/`sk_live_`) que ya guardábamos. Sin ella no se puede automatizar el
 * `organization_id` desde el CSD. Se guarda en su propia columna, cifrada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('facturacion_config', function (Blueprint $table) {
            $table->text('api_key_usuario')->nullable()->after('api_key_produccion');
        });
    }

    public function down(): void
    {
        Schema::table('facturacion_config', function (Blueprint $table) {
            $table->dropColumn('api_key_usuario');
        });
    }
};
