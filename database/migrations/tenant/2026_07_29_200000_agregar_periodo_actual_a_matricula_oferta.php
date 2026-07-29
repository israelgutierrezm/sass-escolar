<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `periodo_actual`: el periodo (grado) en que va el alumno dentro de su plan.
 * Se captura/avanza a mano desde el expediente; lo usa la inscripción masiva
 * para sugerir a los alumnos del grado de un grupo. Nulo = sin definir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('matricula_oferta', function (Blueprint $table) {
            $table->unsignedInteger('periodo_actual')->nullable()->after('generacion');
        });
    }

    public function down(): void
    {
        Schema::table('matricula_oferta', function (Blueprint $table) {
            $table->dropColumn('periodo_actual');
        });
    }
};
