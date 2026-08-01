<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Doble pase de lista en materias teórico-prácticas.
 *
 * El unique de `asistencia_clase` era (inscripción, fecha), y eso IMPEDÍA el
 * caso: un alumno no podía tener registro de la teoría y de la práctica del
 * mismo día. La llave pasa a incluir la modalidad.
 *
 * Quién decide si la materia lleva uno o dos pases es el DOCENTE por grupo
 * —decisión del usuario—, no el plan de estudios: por eso el interruptor vive
 * en `asignatura_grupo` (la materia impartida) y no en `plan_materias`.
 *
 * `unica` es el valor por omisión y el de todo lo ya registrado: la inmensa
 * mayoría de las materias pasan lista una vez y no tienen por qué elegir entre
 * «teórica» y «práctica» cuando no hay tal división.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * Cada paso comprueba si ya está hecho. La primera versión de esta
         * migración reventó a la mitad —el índice viejo sostenía una llave
         * foránea— y dejó la columna creada sin que la migración quedara
         * registrada; al reintentar chocaba contra su propio trabajo. Un paso
         * que ya se hizo no debería impedir terminar el resto.
         */
        if (! Schema::hasColumn('asistencia_clase', 'modalidad')) {
            Schema::table('asistencia_clase', function (Blueprint $table) {
                $table->string('modalidad', 10)->default('unica')->after('fecha');
            });
        }

        /*
         * El índice nuevo se crea ANTES de tirar el viejo. La llave foránea de
         * `inscripcion_id` se apoya en un índice que empiece por esa columna, y
         * MySQL se niega a quedarse sin ninguno: «Cannot drop index … needed in
         * a foreign key constraint». Como el nuevo también empieza por
         * `inscripcion_id`, en cuanto existe puede sostener la FK y el viejo
         * sale sin ruido.
         */
        if (! $this->tieneIndice('asistencia_clase', 'asistencia_unica_por_sesion')) {
            Schema::table('asistencia_clase', function (Blueprint $table) {
                $table->unique(['inscripcion_id', 'fecha', 'modalidad'], 'asistencia_unica_por_sesion');
            });
        }

        if ($this->tieneIndice('asistencia_clase', 'asistencia_clase_inscripcion_id_fecha_unique')) {
            Schema::table('asistencia_clase', function (Blueprint $table) {
                $table->dropUnique('asistencia_clase_inscripcion_id_fecha_unique');
            });
        }

        if (! Schema::hasColumn('asignatura_grupo', 'doble_pase_lista')) {
            Schema::table('asignatura_grupo', function (Blueprint $table) {
                $table->boolean('doble_pase_lista')->default(false)->after('situacion_id');
            });
        }
    }

    private function tieneIndice(string $tabla, string $indice): bool
    {
        return collect(DB::select("SHOW INDEX FROM `{$tabla}`"))
            ->contains(fn ($i) => $i->Key_name === $indice);
    }

    public function down(): void
    {
        Schema::table('asignatura_grupo', fn (Blueprint $t) => $t->dropColumn('doble_pase_lista'));

        Schema::table('asistencia_clase', function (Blueprint $table) {
            $table->dropUnique('asistencia_unica_por_sesion');
            $table->dropColumn('modalidad');
        });

        Schema::table('asistencia_clase', function (Blueprint $table) {
            $table->unique(['inscripcion_id', 'fecha']);
        });
    }
};
