<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Se elimina el override `plan_materias.creditos_en_plan`: los créditos de una
 * materia son siempre los del catálogo de la asignatura. La columna existía pero
 * ningún formulario la alimentaba (dato fantasma), así que se retira junto con
 * su badge «ajustado». Todas las lecturas caían con `?? asignatura.creditos`, de
 * modo que quitarla no cambia ningún total.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plan_materias', function (Blueprint $table) {
            $table->dropColumn('creditos_en_plan');
        });
    }

    public function down(): void
    {
        Schema::table('plan_materias', function (Blueprint $table) {
            $table->float('creditos_en_plan')->nullable()->after('tipo');
        });
    }
};
