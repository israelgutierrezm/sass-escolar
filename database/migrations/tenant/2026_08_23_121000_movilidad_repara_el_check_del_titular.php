<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Módulo 12 · El CHECK del titular de una postulación de movilidad, que no se
 * pudo crear.
 *
 * ── Por qué falló ─────────────────────────────────────────────────────────
 * MySQL 8 rechaza con el error **3823** una columna que participa en un CHECK
 * y además tiene una foránea con ACCIÓN REFERENCIAL. Las dos columnas se
 * declararon con `nullOnDelete()`, así que el
 * `ALTER TABLE … ADD CONSTRAINT CHECK` reventó.
 *
 * ── Y `nullOnDelete` era además lo incorrecto ─────────────────────────────
 * Poner en NULL la matrícula al borrarla dejaría la postulación SIN NINGÚN
 * titular, que es exactamente el estado que el CHECK existe para impedir. La
 * acción correcta es la de `adeudos`, que tiene el mismo titular dual:
 * `constrained()` a secas —RESTRICT—, porque una postulación de movilidad es
 * historia y no debe desaparecer por detrás.
 *
 * ── La lección, que ya estaba anotada y volvió a morder ───────────────────
 * «Una migración que puede fallar a la mitad comprueba antes de actuar.» La
 * anterior lo hacía, pero metió el CHECK DENTRO del `if (! hasTable)`: al
 * reintentar, la tabla ya existía, se saltó el bloque entero y el CHECK quedó
 * sin crear PARA SIEMPRE, con la migración marcada como aplicada. Comprobar
 * antes de actuar es por PIEZA, no por bloque.
 */
return new class extends Migration
{
    private const CHECK = 'chk_movilidad_titular';

    public function up(): void
    {
        if (! Schema::hasTable('postulaciones_movilidad')) {
            return;
        }

        $this->sinAccionReferencial();

        if ($this->existeElCheck()) {
            return;
        }

        DB::statement(
            'ALTER TABLE `postulaciones_movilidad` ADD CONSTRAINT `'.self::CHECK.'` CHECK ('
            .'(`matricula_oferta_id` IS NOT NULL AND `persona_externa_id` IS NULL) OR '
            .'(`matricula_oferta_id` IS NULL AND `persona_externa_id` IS NOT NULL))'
        );
    }

    public function down(): void
    {
        if ($this->existeElCheck()) {
            DB::statement('ALTER TABLE `postulaciones_movilidad` DROP CHECK `'.self::CHECK.'`');
        }
    }

    /** Rehace las dos foráneas sin `ON DELETE SET NULL`. */
    private function sinAccionReferencial(): void
    {
        foreach ([
            'postulaciones_movilidad_matricula_oferta_id_foreign' => ['matricula_oferta_id', 'matricula_oferta'],
            'postulaciones_movilidad_persona_externa_id_foreign' => ['persona_externa_id', 'personas'],
        ] as $nombre => [$columna, $referida]) {
            $accion = DB::selectOne(
                'SELECT DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS '
                .'WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = ?',
                [$nombre],
            );

            // Ya está como debe: no se toca. Reintentar no puede chocar contra
            // su propio trabajo.
            if ($accion === null || $accion->DELETE_RULE === 'RESTRICT' || $accion->DELETE_RULE === 'NO ACTION') {
                continue;
            }

            DB::statement("ALTER TABLE `postulaciones_movilidad` DROP FOREIGN KEY `{$nombre}`");
            DB::statement(
                "ALTER TABLE `postulaciones_movilidad` ADD CONSTRAINT `{$nombre}` "
                ."FOREIGN KEY (`{$columna}`) REFERENCES `{$referida}` (`id`)"
            );
        }
    }

    private function existeElCheck(): bool
    {
        return DB::selectOne(
            'SELECT 1 AS hay FROM information_schema.TABLE_CONSTRAINTS '
            .'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?',
            ['postulaciones_movilidad', self::CHECK],
        ) !== null;
    }
};
