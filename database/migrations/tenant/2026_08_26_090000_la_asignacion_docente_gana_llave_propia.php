<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `docente_asignatura_grupo` gana una llave sustituta y puede RETIRARSE.
 *
 * ── Dos problemas, una causa ─────────────────────────────────────────────
 * La tabla nació con PK compuesta `(asignatura_grupo_id, persona_id)` y sin
 * columna `id`. Eso cerraba dos puertas a la vez:
 *
 *  1. **No se podía RETIRAR una asignación conservándola.** La tabla declara
 *     `$table->auditoria()` —o sea borrado lógico— pero con esa llave el
 *     borrado lógico era imposible de usar: la fila retirada seguía ocupando el
 *     par, así que volver a asignarle esa materia al mismo docente reventaba
 *     con `Duplicate entry` PARA SIEMPRE. Comprobado contra el demo antes de
 *     escribir esto. Por eso la pantalla usaba `detach()`, que BORRA de verdad:
 *     de quien dio una materia medio semestre no quedaba ni rastro, mientras el
 *     acta que firmó sigue nombrándolo.
 *
 *     Es la misma trampa que ya documentó `Colocacion` en la bolsa de trabajo
 *     —«MySQL no distingue una fila dada de baja de una viva»—.
 *
 *  2. **No se podía recorrer por lotes.** El motor de reportes avanza su cursor
 *     con UNA columna llave, así que un reporte con grano de ASIGNACIÓN —la
 *     carga académica, que es la pregunta natural del área de docentes— no se
 *     podía construir.
 *
 * ── Por qué el único va sobre una columna GENERADA y no sobre `deleted_at` ──
 * La salida obvia es `unique(asignatura_grupo_id, persona_id, deleted_at)`:
 * MySQL trata los NULL como distintos, así que caben muchas retiradas y una
 * viva. **Y está mal**, se probó: `deleted_at` es un `timestamp` de precisión de
 * SEGUNDO, así que retirar → reasignar → retirar dentro del mismo segundo pone
 * dos filas con el mismo valor y choca. No es teórico: la propia suite de esta
 * migración lo hace en un segundo y reventaba con `Duplicate entry
 * '42-374-2026-08-26 14:23:37'`.
 *
 * Con una columna VIRTUAL —`1` si está viva, `NULL` si está retirada— el único
 * dice exactamente lo que se quiere decir: **a lo más una asignación VIGENTE
 * por par**, y retiradas las que hagan falta. Y no hay segunda verdad que
 * mantener: la columna se DERIVA de `deleted_at`, no se escribe.
 *
 * ── Por qué no hace falta `IndiceQueSostieneUnaFk` ───────────────────────
 * Las dos foráneas siguen sostenidas al tirar la PK, comprobado leyendo los
 * índices reales: `persona_id` tiene el suyo (`..._persona_id_foreign`) y
 * `asignatura_grupo_id` lo cubre `..._asignatura_grupo_id_tipo_index`, que
 * empieza por esa columna. Aun así el único nuevo se crea ANTES de tirar la
 * vieja, que es la regla de la casa.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * Comprobar antes de actuar, PIEZA POR PIEZA y no por bloque.
         *
         * Es la lección que dejó el CHECK de movilidad: con el `if` envolviendo
         * el bloque entero, un reintento tras un fallo parcial se lo saltaba y
         * la pieza quedaba sin crear PARA SIEMPRE, con la migración marcada como
         * aplicada.
         */
        if (! Schema::hasColumn('docente_asignatura_grupo', 'vigente')) {
            DB::statement('
                ALTER TABLE docente_asignatura_grupo
                ADD COLUMN vigente TINYINT UNSIGNED
                    GENERATED ALWAYS AS (IF(deleted_at IS NULL, 1, NULL)) VIRTUAL
            ');
        }

        if (! $this->tieneIndice('docente_asignatura_grupo_una_vigente')) {
            DB::statement('
                ALTER TABLE docente_asignatura_grupo
                ADD UNIQUE docente_asignatura_grupo_una_vigente (asignatura_grupo_id, persona_id, vigente)
            ');
        }

        if ($this->tieneIndice('PRIMARY') && ! Schema::hasColumn('docente_asignatura_grupo', 'id')) {
            DB::statement('ALTER TABLE docente_asignatura_grupo DROP PRIMARY KEY');
        }

        if (! Schema::hasColumn('docente_asignatura_grupo', 'id')) {
            // El auto_increment TIENE que ser llave, así que entra ya como PK.
            DB::statement('
                ALTER TABLE docente_asignatura_grupo
                ADD id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY FIRST
            ');
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('docente_asignatura_grupo', 'id')) {
            return;
        }

        /*
         * Volver atrás EXIGE que no haya retiradas.
         *
         * Con la llave compuesta, dos filas del mismo par no caben — y hoy sí
         * caben si una está retirada. Bajar a ciegas reventaría a media
         * migración; se dice qué hay que resolver antes.
         */
        $retiradas = DB::table('docente_asignatura_grupo')->whereNotNull('deleted_at')->count();

        if ($retiradas > 0) {
            throw new RuntimeException(
                "No se puede volver a la llave compuesta: hay {$retiradas} asignaciones retiradas, y con "
                .'esa llave no caben junto a las vivas del mismo par. Bórralas de verdad primero.'
            );
        }

        DB::statement('ALTER TABLE docente_asignatura_grupo DROP PRIMARY KEY, DROP COLUMN id');
        DB::statement('ALTER TABLE docente_asignatura_grupo ADD PRIMARY KEY (asignatura_grupo_id, persona_id)');
        DB::statement('ALTER TABLE docente_asignatura_grupo DROP INDEX docente_asignatura_grupo_una_vigente');
        DB::statement('ALTER TABLE docente_asignatura_grupo DROP COLUMN vigente');
    }

    private function tieneIndice(string $nombre): bool
    {
        return DB::select(
            'SHOW INDEX FROM docente_asignatura_grupo WHERE Key_name = ?',
            [$nombre],
        ) !== [];
    }
};
