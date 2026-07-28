<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Al subir el CSD de una razón social, el sistema crea su organización en
 * Facturapi y ésta devuelve su propia llave de PRUEBAS (`sk_test_...`), con la
 * que se timbra en pruebas a nombre de ESA razón social. Se guarda cifrada en el
 * emisor (cada organización tiene la suya), junto con la marca de cuándo se
 * sincronizó por última vez. El `facturapi_id` (= organization_id) ya existía.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('emisores_fiscales', function (Blueprint $table) {
            $table->text('facturapi_key_pruebas')->nullable()->after('facturapi_id');
            $table->timestamp('facturapi_sincronizado_en')->nullable()->after('facturapi_key_pruebas');
        });
    }

    public function down(): void
    {
        Schema::table('emisores_fiscales', function (Blueprint $table) {
            $table->dropColumn(['facturapi_key_pruebas', 'facturapi_sincronizado_en']);
        });
    }
};
