<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `cat_observacion_asignatura` de la SEP: el estatus académico con el que se
 * cursó/cargó una asignatura en el historial (equivalencia, extraordinario,
 * curso de verano, revalidación, normal/ordinario, exento…).
 *
 * Es un catálogo OFICIAL propio (ids 70–78 y 100–104), separado de la mecánica
 * interna de captura (`tipos_evaluacion`, `estatus_historial`) y de las notas de
 * acta (`observaciones_historial`). El historial gana una columna dedicada,
 * `observacion_asignatura_id`, que `AsentadorActa` fija al asentar (derivada del
 * tipo de evaluación del renglón).
 */
return new class extends Migration
{
    /** [id oficial, clave, nombre, abreviatura|null] */
    private const OFICIALES = [
        [70, 'equivalencia_estudios', 'EQUIVALENCIA DE ESTUDIOS', 'E.'],
        [71, 'examen_extraordinario', 'EXAMEN EXTRAORDINARIO', 'E.E.'],
        [72, 'a_titulo_suficiencia', 'EXAMEN A TÍTULO DE SUFICIENCIA', 'E.T.S.'],
        [73, 'curso_verano', 'CURSO DE VERANO', 'C.V.'],
        [74, 'recursamiento', 'RECURSAMIENTO', 'Rec.'],
        [75, 'reingreso', 'REINGRESO', 'Rein.'],
        [76, 'acuerdo_regularizacion', 'ACUERDO REGULARIZACIÓN', 'A.C.'],
        [77, 'cambio_acuerdo_rvoe', 'CON CAMBIO EN EL ACUERDO DE RVOE', 'C.A.'],
        [78, 'revalidacion_estudios', 'REVALIDACIÓN DE ESTUDIOS', 'R.'],
        [100, 'normal', 'NORMAL / ORDINARIO', null],
        [101, 'correspondencia_asignatura', 'CORRESPONDENCIA DE ASIGNATURA', 'C.A.P.'],
        [102, 'exento', 'EXENTO', 'EX.'],
        [103, 'acreditado', 'ACREDITADO O APROBADO', 'A.'],
        [104, 'curso_regularizacion', 'CURSO DE REGULARIZACIÓN', 'C.R.'],
    ];

    /** tipo_evaluacion.clave → id oficial de la observación de asignatura. */
    private const DESDE_TIPO_EVALUACION = [
        'ordinaria' => 100,
        'extraordinaria' => 71,
        'a_titulo' => 72,
        'recursamiento' => 74,
        'revalidacion' => 78,
        'regularizacion' => 104,
    ];

    public function up(): void
    {
        Schema::create('observaciones_asignatura', function (Blueprint $table) {
            $table->id();
            $table->string('clave', 50)->unique();
            $table->string('nombre', 150);
            $table->string('abreviatura', 20)->nullable();
            $table->boolean('protegido')->default(false);
            $table->auditoria();
        });

        $ahora = now();
        foreach (self::OFICIALES as [$id, $clave, $nombre, $abreviatura]) {
            DB::table('observaciones_asignatura')->insert([
                'id' => $id,
                'clave' => $clave,
                'nombre' => $nombre,
                'abreviatura' => $abreviatura,
                'protegido' => true,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ]);
        }

        Schema::table('historial', function (Blueprint $table) {
            $table->foreignId('observacion_asignatura_id')->nullable()->after('observacion_id')
                ->constrained('observaciones_asignatura')->nullOnDelete();
        });

        // Backfill del historial existente: se deriva del tipo de evaluación de
        // cada renglón (ordinaria → NORMAL/ORDINARIO, extraordinaria → …).
        $case = 'CASE t.clave';
        foreach (self::DESDE_TIPO_EVALUACION as $clave => $id) {
            $case .= " WHEN '{$clave}' THEN {$id}";
        }
        $case .= ' ELSE 100 END';

        DB::statement(
            "UPDATE historial h JOIN tipos_evaluacion t ON t.id = h.tipo_evaluacion_id
             SET h.observacion_asignatura_id = {$case}"
        );
    }

    public function down(): void
    {
        Schema::table('historial', function (Blueprint $table) {
            $table->dropConstrainedForeignId('observacion_asignatura_id');
        });

        Schema::dropIfExists('observaciones_asignatura');
    }
};
