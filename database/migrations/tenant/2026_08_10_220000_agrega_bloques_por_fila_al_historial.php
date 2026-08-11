<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cuántos bloques de periodo caben en una fila del historial impreso.
 *
 * ── Para qué ──────────────────────────────────────────────────────────────
 * Para imprimir «Periodo 1» y «Periodo 2» uno al lado del otro, y «Periodo 3»
 * y «Periodo 4» en la fila siguiente. Es como está maquetado el historial del
 * Colegio de Bachilleres que sirvió de referencia, y no es capricho: un
 * bachillerato tiene seis bloques de siete materias, y a una columna eso son
 * tres hojas de papel medio vacías. A dos columnas cabe en una.
 *
 * Uno o dos, y nada más. Con tres, el nombre de una asignatura no cabe en su
 * celda a un tamaño legible —medido sobre carta con los márgenes de impresión—,
 * así que ofrecerlo sería ofrecer un documento ilegible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('disenos_historial', function (Blueprint $table) {
            $table->unsignedTinyInteger('bloques_por_fila')->default(1)->after('agrupacion');
        });
    }

    public function down(): void
    {
        Schema::table('disenos_historial', function (Blueprint $table) {
            $table->dropColumn('bloques_por_fila');
        });
    }
};
