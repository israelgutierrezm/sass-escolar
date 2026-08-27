<?php

declare(strict_types=1);

use App\Support\IndiceQueSostieneUnaFk;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `ejecuciones_reporte` gana un índice por FECHA.
 *
 * ── Por qué el que ya existe no sirve para lo que se va a hacer ──────────
 * El único índice útil es `(reporte, created_at)`, y MySQL sólo lo puede usar
 * si se filtra por `reporte` PRIMERO. Las dos cosas que llegan ahora entran por
 * la fecha sin saber el reporte:
 *
 *  - el LISTADO de la pantalla de auditoría, que es lo primero que se abre;
 *  - la PURGA, que borra todo lo anterior a una fecha.
 *
 * Medido con EXPLAIN sobre el demo antes de esto: el listado por fecha salía
 * `type=ALL, key=NULL, Using filesort`, o sea escaneando la tabla entera. Con
 * 119 filas no duele; esta tabla escribe una fila por cada reporte que alguien
 * abre, así que es de las que crecen solas.
 *
 * ── Por qué NO se indexa nada más ────────────────────────────────────────
 * Es una tabla de ESCRITURA constante y un índice de más se paga en cada
 * inserción, para siempre. `formato` tiene cardinalidad 3 —pantalla, xlsx,
 * csv— y no merece uno; `persona_id` ya tiene el suyo porque lo sostiene su
 * foránea, y por eso mismo no se puede tirar sin crear antes el sustituto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ejecuciones_reporte', function (Blueprint $tabla) {
            // Comprobar antes de actuar: un reintento tras un fallo parcial no
            // debe chocar contra su propio trabajo.
            if (! IndiceQueSostieneUnaFk::existe('ejecuciones_reporte', 'ejecuciones_reporte_created_at_index')) {
                $tabla->index('created_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('ejecuciones_reporte', function (Blueprint $tabla) {
            if (IndiceQueSostieneUnaFk::existe('ejecuciones_reporte', 'ejecuciones_reporte_created_at_index')) {
                $tabla->dropIndex('ejecuciones_reporte_created_at_index');
            }
        });
    }
};
