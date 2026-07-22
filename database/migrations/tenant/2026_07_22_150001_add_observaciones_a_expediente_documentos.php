<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El rechazo de un documento del aspirante necesita motivo.
 *
 * INCONSISTENCIA CORREGIDA: al docente ya no se le puede rechazar un documento
 * sin observación —es una decisión tomada en el hito de su expediente, porque
 * un rechazo sin motivo obliga a adivinar qué corregir— pero al ASPIRANTE sí se
 * podía: `expediente_documentos` no tenía dónde guardarlo. Nadie lo notó
 * mientras solo un administrador miraba esa pantalla; se vuelve grave ahora que
 * el interesado ve su propio expediente y lee «Rechazado» sin más.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expediente_documentos', function (Blueprint $table) {
            $table->string('observaciones', 255)->nullable()->after('estado_documento_id');
        });
    }

    public function down(): void
    {
        Schema::table('expediente_documentos', function (Blueprint $table) {
            $table->dropColumn('observaciones');
        });
    }
};
