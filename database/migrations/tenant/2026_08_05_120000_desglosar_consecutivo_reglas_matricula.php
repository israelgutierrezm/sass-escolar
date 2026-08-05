<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El consecutivo de la matrícula son DOS decisiones, no una.
 *
 * `ambito_consecutivo` era una lista plana de seis valores —global, anio,
 * carrera, plan, carrera_anio, plan_anio— que mezclaba dos preguntas
 * independientes que las escuelas hacen por separado:
 *
 *   1. ¿Un contador para toda la escuela, o uno por campus / nivel / carrera /
 *      plan? («el 1 de Derecho y el 1 de Medicina son distintos»)
 *   2. ¿Se reinicia cada año, o es histórico? («el 0001 vuelve en enero» vs
 *      «el 4822 sigue subiendo desde que abrimos»)
 *
 * Con la lista plana faltaban combinaciones que sí existen —contador por
 * CAMPUS y por año, contador por NIVEL— y no había forma de agregarlas sin
 * seguir inventando nombres compuestos. Como dos columnas son diez
 * combinaciones en vez de seis, y la pantalla puede preguntarlo como lo que es:
 * dos desplegables.
 *
 * La equivalencia con lo anterior es exacta, así que ninguna escuela que ya
 * tuviera su regla configurada cambia de numeración.
 */
return new class extends Migration
{
    /** Lo viejo → [consecutivo_por, consecutivo_anual]. */
    private const EQUIVALENCIA = [
        'global' => [null, false],
        'anio' => [null, true],
        'carrera' => ['carrera', false],
        'plan' => ['plan', false],
        'carrera_anio' => ['carrera', true],
        'plan_anio' => ['plan', true],
    ];

    public function up(): void
    {
        Schema::table('reglas_matricula', function (Blueprint $table) {
            // Un nombre para poder distinguirlas en la pantalla: una escuela
            // llega a tener la global más una por posgrado y otra por campus.
            $table->string('nombre', 100)->nullable()->after('id');
            // NULL = un solo contador para toda la escuela.
            $table->string('consecutivo_por', 20)->nullable()->after('plantilla');
            $table->boolean('consecutivo_anual')->default(true)->after('consecutivo_por');
        });

        foreach (self::EQUIVALENCIA as $viejo => [$por, $anual]) {
            DB::table('reglas_matricula')
                ->where('ambito_consecutivo', $viejo)
                ->update(['consecutivo_por' => $por, 'consecutivo_anual' => $anual]);
        }

        Schema::table('reglas_matricula', function (Blueprint $table) {
            $table->dropColumn('ambito_consecutivo');
        });

        $this->renombrarContadores();
    }

    /**
     * Y los contadores YA EMITIDOS se renombran a la llave nueva.
     *
     * La llave del contador se arma con las dos partes: «global|anio:2026». La
     * de antes, para el mismo caso, era sólo «anio:2026». Las demás coinciden
     * por casualidad —«carrera:12», «plan:3|anio:2026»— pero ésta no, y dejarla
     * sin tocar haría que el generador no encontrara la fila y empezara otra vez
     * en 1: matrículas repetidas contra las que ya están impresas.
     *
     * Con `updateOrInsert` no revienta si por alguna razón ya existiera la
     * llave nueva; se conserva el valor MÁS ALTO, que es el que representa los
     * folios realmente emitidos.
     */
    private function renombrarContadores(): void
    {
        $viejos = DB::table('contadores_matricula')
            ->where('clave', 'like', 'anio:%')
            ->get();

        foreach ($viejos as $contador) {
            $nueva = "global|{$contador->clave}";
            $existente = (int) DB::table('contadores_matricula')->where('clave', $nueva)->value('valor');

            DB::table('contadores_matricula')->updateOrInsert(
                ['clave' => $nueva],
                ['valor' => max($existente, (int) $contador->valor), 'updated_at' => now()],
            );

            DB::table('contadores_matricula')->where('clave', $contador->clave)->delete();
        }
    }

    public function down(): void
    {
        // Los contadores vuelven a su nombre de antes, o la vuelta atrás
        // reiniciaría la numeración por el mismo motivo.
        foreach (DB::table('contadores_matricula')->where('clave', 'like', 'global|anio:%')->get() as $contador) {
            DB::table('contadores_matricula')
                ->where('clave', $contador->clave)
                ->update(['clave' => str_replace('global|', '', $contador->clave)]);
        }

        Schema::table('reglas_matricula', function (Blueprint $table) {
            $table->string('ambito_consecutivo', 20)->default('anio')->after('plantilla');
        });

        foreach (self::EQUIVALENCIA as $viejo => [$por, $anual]) {
            DB::table('reglas_matricula')
                ->where('consecutivo_anual', $anual)
                ->when($por === null,
                    fn ($q) => $q->whereNull('consecutivo_por'),
                    fn ($q) => $q->where('consecutivo_por', $por),
                )
                ->update(['ambito_consecutivo' => $viejo]);
        }

        Schema::table('reglas_matricula', function (Blueprint $table) {
            $table->dropColumn(['nombre', 'consecutivo_por', 'consecutivo_anual']);
        });
    }
};
