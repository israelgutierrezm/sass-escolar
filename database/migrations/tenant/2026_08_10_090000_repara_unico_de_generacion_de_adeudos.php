<?php

declare(strict_types=1);

use App\Support\IndiceQueSostieneUnaFk;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Devuelve al único de generación de adeudos la tercera columna que perdió.
 *
 * ── Qué pasó ───────────────────────────────────────────────────────────────
 * `adeudos_generacion_unique` nació sobre
 * `(matricula_oferta_id, regla_id, periodo_etiqueta)`. Después
 * `reorganiza_planes_cobro` eliminó `regla_id` de la tabla, y MySQL sacó esa
 * columna del índice sin decir nada: quedó en `(matricula_oferta_id,
 * periodo_etiqueta)`. Dos columnas, mucho más estricto de lo que nadie decidió
 * —una matrícula admite UN solo cargo por periodo—.
 *
 * Comprobado insertando contra la base real antes de escribir esto: el segundo
 * cargo del mismo periodo responde `Duplicate entry '431-PRUEBA-2026-1'`.
 *
 * ── Por qué la tercera columna es `concepto_plan_id` ───────────────────────
 * Porque es por la que pregunta `GeneradorAdeudos::generarCargos` para no
 * duplicar. Con el índice como estaba, esa comprobación no tenía red debajo: la
 * idempotencia dependía sólo de su `SELECT` previo, y dos corridas simultáneas
 * podían colarse las dos. Ahora la base lo sostiene.
 *
 * ── Por qué el nuevo se crea ANTES de tirar el viejo ───────────────────────
 * Los dos empiezan por `matricula_oferta_id`, que es una llave foránea, y MySQL
 * exige que alguna esté cubierta. Tirando primero el viejo, el `DROP` falla con
 * «needed in a foreign key constraint». Creando primero el nuevo —que empieza
 * por la misma columna y por tanto la cubre—, el viejo se puede retirar sin
 * dejar la foránea descubierta ni un instante.
 *
 * ── Sobre los datos que ya están ───────────────────────────────────────────
 * El nuevo único es MÁS PERMISIVO que el que sustituye: todo lo que cabía en dos
 * columnas cabe en tres. Así que no hay filas que puedan violarlo y la migración
 * no necesita limpiar nada.
 */
return new class extends Migration
{
    private const VIEJO = 'adeudos_generacion_unique';

    private const NUEVO = 'adeudos_generacion_unica';

    public function up(): void
    {
        // Comprobar antes de actuar: un reintento tras un fallo parcial no debe
        // chocar contra su propio trabajo.
        if (! IndiceQueSostieneUnaFk::existe('adeudos', self::NUEVO)) {
            Schema::table('adeudos', function (Blueprint $table) {
                $table->unique(
                    ['matricula_oferta_id', 'concepto_plan_id', 'periodo_etiqueta'],
                    self::NUEVO,
                );
            });
        }

        if (IndiceQueSostieneUnaFk::existe('adeudos', self::VIEJO)) {
            Schema::table('adeudos', fn (Blueprint $table) => $table->dropUnique(self::VIEJO));
        }
    }

    public function down(): void
    {
        // Mismo cuidado en orden inverso: se repone el viejo y sólo entonces se
        // retira el nuevo, para que la foránea nunca se quede sin quien la
        // cubra. Ojo: volver atrás puede fallar si para entonces ya existen dos
        // cargos del mismo periodo —que es justo lo que esta migración permite—.
        if (! IndiceQueSostieneUnaFk::existe('adeudos', self::VIEJO)) {
            Schema::table('adeudos', function (Blueprint $table) {
                $table->unique(['matricula_oferta_id', 'periodo_etiqueta'], self::VIEJO);
            });
        }

        if (IndiceQueSostieneUnaFk::existe('adeudos', self::NUEVO)) {
            Schema::table('adeudos', fn (Blueprint $table) => $table->dropUnique(self::NUEVO));
        }
    }
};
