<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El único de `reglas_recordatorio_cobranza.dias` no distinguía una fila dada
 * de baja.
 *
 * Un peldaño se RETIRA en baja lógica —sus recordatorios ya emitidos lo
 * nombran—, y con el único sobre la columna pelada MySQL sigue viendo esa fila:
 * la escuela que retirara el peldaño de ocho días no podría crear otro para el
 * día ocho NUNCA MÁS. Y peor que no poder: la validación de la pantalla sí
 * mira `deleted_at`, así que dejaba pasar el alta y la base reventaba con un
 * 1062 en la cara de quien captura.
 *
 * Es la trampa que este proyecto ya se cobró dos veces —las colocaciones de la
 * bolsa y el pivote del convenio—, y aquí no se resuelve con `forceDelete`
 * porque la fila tiene que sobrevivir para explicar qué decía el mensaje que la
 * familia recibió.
 *
 * La solución es la de `sesiones_caja`: una COLUMNA GENERADA que vale `dias`
 * mientras la fila está viva y NULL cuando se retira. MySQL considera distintos
 * dos NULL, así que el único vigila lo vivo y deja pasar todos los retiros.
 * Un `unique(dias, deleted_at)` NO sirve: dos filas vivas tienen las dos
 * `deleted_at` en NULL y MySQL las daría por distintas.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('reglas_recordatorio_cobranza')) {
            return;
        }

        $tabla = 'reglas_recordatorio_cobranza';

        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        // Comprobar antes de actuar, PIEZA POR PIEZA: un reintento tras un
        // fallo parcial no debe chocar contra su propio trabajo.
        if (! Schema::hasColumn($tabla, 'dias_si_vive')) {
            DB::statement(
                "ALTER TABLE {$tabla} ADD COLUMN dias_si_vive SMALLINT
                 GENERATED ALWAYS AS (CASE WHEN deleted_at IS NULL THEN dias END) STORED"
            );
        }

        $viejo = collect(DB::select("SHOW INDEX FROM {$tabla} WHERE Key_name = ?", ["{$tabla}_dias_unique"]));
        $nuevo = collect(DB::select("SHOW INDEX FROM {$tabla} WHERE Key_name = ?", ["{$tabla}_dias_si_vive_unique"]));

        // El nuevo ANTES de tirar el viejo: durante el hueco, dos altas
        // simultáneas para el mismo día pasarían las dos.
        if ($nuevo->isEmpty()) {
            DB::statement("ALTER TABLE {$tabla} ADD UNIQUE {$tabla}_dias_si_vive_unique (dias_si_vive)");
        }

        if ($viejo->isNotEmpty()) {
            DB::statement("ALTER TABLE {$tabla} DROP INDEX {$tabla}_dias_unique");
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('reglas_recordatorio_cobranza') || DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        $tabla = 'reglas_recordatorio_cobranza';

        if (collect(DB::select("SHOW INDEX FROM {$tabla} WHERE Key_name = ?", ["{$tabla}_dias_unique"]))->isEmpty()) {
            DB::statement("ALTER TABLE {$tabla} ADD UNIQUE {$tabla}_dias_unique (dias)");
        }

        if (collect(DB::select("SHOW INDEX FROM {$tabla} WHERE Key_name = ?", ["{$tabla}_dias_si_vive_unique"]))->isNotEmpty()) {
            DB::statement("ALTER TABLE {$tabla} DROP INDEX {$tabla}_dias_si_vive_unique");
        }

        if (Schema::hasColumn($tabla, 'dias_si_vive')) {
            Schema::table($tabla, fn (Blueprint $t) => $t->dropColumn('dias_si_vive'));
        }
    }
};
