<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El consecutivo se cuenta sobre VARIAS dimensiones, y se reinicia por ciclo.
 *
 * Dos límites del modelo anterior que las escuelas sí pisan:
 *
 *  1. `consecutivo_por` guardaba UNA dimensión: se podía contar por campus o
 *     por carrera, nunca «por campus Y carrera». Existe —dos campus que además
 *     numeran aparte cada carrera— y no había forma de escribirlo.
 *
 *  2. `consecutivo_anual` era un sí/no sobre el año CALENDARIO. Una escuela
 *     cuatrimestral no piensa en años: reinicia en el cuatrimestre que empieza,
 *     no en enero. Pasa a tres valores: nunca | anio | ciclo.
 *
 * ── Los contadores ya emitidos NO se tocan ──────────────────────────────────
 * La llave se sigue armando igual —dimensiones ordenadas, y al final el
 * reinicio—, así que una regla de una sola dimensión produce exactamente la
 * misma cadena que antes: «carrera:12|anio:2026». Sólo cambian de forma las
 * reglas que alguien configure con dos dimensiones, que hoy no existen porque
 * no se podían crear. Ver `GeneradorMatricula::claveContador`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reglas_matricula', function (Blueprint $table) {
            // Lista de dimensiones. Vacía = un solo contador para la escuela.
            $table->json('consecutivo_dimensiones')->nullable()->after('plantilla');
            $table->string('consecutivo_reinicia', 10)->default('anio')->after('consecutivo_dimensiones');
        });

        // Una dimensión sola se convierte en una lista de uno.
        DB::table('reglas_matricula')->orderBy('id')->each(function (object $regla) {
            DB::table('reglas_matricula')->where('id', $regla->id)->update([
                'consecutivo_dimensiones' => json_encode(
                    $regla->consecutivo_por === null ? [] : [$regla->consecutivo_por],
                ),
                'consecutivo_reinicia' => $regla->consecutivo_anual ? 'anio' : 'nunca',
            ]);
        });

        Schema::table('reglas_matricula', function (Blueprint $table) {
            $table->dropColumn(['consecutivo_por', 'consecutivo_anual']);
        });
    }

    public function down(): void
    {
        Schema::table('reglas_matricula', function (Blueprint $table) {
            $table->string('consecutivo_por', 20)->nullable()->after('plantilla');
            $table->boolean('consecutivo_anual')->default(true)->after('consecutivo_por');
        });

        /*
         * La vuelta atrás PIERDE información y no puede no perderla: una regla
         * con dos dimensiones no cabe en una columna, y «reinicia por ciclo» no
         * existía. Se conserva la primera dimensión y el reinicio por ciclo se
         * degrada a anual, que es lo más parecido.
         */
        DB::table('reglas_matricula')->orderBy('id')->each(function (object $regla) {
            $dimensiones = json_decode((string) $regla->consecutivo_dimensiones, true) ?: [];

            DB::table('reglas_matricula')->where('id', $regla->id)->update([
                'consecutivo_por' => $dimensiones[0] ?? null,
                'consecutivo_anual' => $regla->consecutivo_reinicia !== 'nunca',
            ]);
        });

        Schema::table('reglas_matricula', function (Blueprint $table) {
            $table->dropColumn(['consecutivo_dimensiones', 'consecutivo_reinicia']);
        });
    }
};
