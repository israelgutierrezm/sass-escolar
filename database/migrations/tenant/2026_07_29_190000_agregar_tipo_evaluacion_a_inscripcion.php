<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La inscripción gana el TIPO DE EVALUACIÓN con el que el alumno cursa la
 * materia (ordinaria, extraordinaria, a título de suficiencia, recursamiento,
 * revalidación, regularización): el catálogo completo, no solo el `tipo`
 * ordinaria/recursamiento que había. Al asentar el acta, el kárdex hereda este
 * tipo. Se conserva `tipo` porque el validador y el asentador lo consultan; se
 * deriva de éste.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inscripcion', function (Blueprint $table) {
            $table->foreignId('tipo_evaluacion_id')->nullable()->after('tipo')
                ->constrained('tipos_evaluacion')->nullOnDelete();
        });

        // Backfill: se deriva del `tipo` que ya tenía cada inscripción.
        DB::statement(
            "UPDATE inscripcion i
             JOIN tipos_evaluacion t ON t.clave = CASE i.tipo WHEN 'recursamiento' THEN 'recursamiento' ELSE 'ordinaria' END
             SET i.tipo_evaluacion_id = t.id"
        );
    }

    public function down(): void
    {
        Schema::table('inscripcion', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tipo_evaluacion_id');
        });
    }
};
