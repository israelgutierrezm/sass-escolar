<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La modalidad del expediente: dónde hace ESTE alumno su proceso.
 *
 * ── Por qué no basta con la de la plaza ───────────────────────────────────
 * `plazas_proceso.modalidad_id` dice cómo se ofrece esa plaza, y un expediente
 * puede no tener plaza —los tipos con `exige_plaza` apagado, el alumno que
 * llega con su carta—. Derivándola, la mitad de los expedientes se quedaría sin
 * modalidad y el catálogo de la fase 1 no lo leería nadie: la clase de defecto
 * que este proyecto ya retiró tres veces («declarado y sin lector»).
 *
 * ── Y ya tenía un lector esperándola ──────────────────────────────────────
 * `CatalogoProcesosController` pregunta si una modalidad está EN USO mirando
 * `expedientes_proceso.modalidad_id`. Su guarda comprobaba que la tabla
 * existiera —y en la fase 1 no existía—, así que devolvía false; al crearla la
 * fase 4 sin esta columna, la consulta empezó a reventar con «Unknown column».
 * Lo cazó la suite de catálogos en el barrido. La guarda se corrigió para mirar
 * la COLUMNA y no sólo la tabla; esta migración pone la columna que faltaba.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('expedientes_proceso', 'modalidad_id')) {
            return;
        }

        Schema::table('expedientes_proceso', function (Blueprint $tabla) {
            // Nullable: se decide al asignar, y hasta entonces no se sabe.
            $tabla->foreignId('modalidad_id')
                ->nullable()
                ->after('plaza_id')
                ->constrained('modalidades_proceso');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('expedientes_proceso', 'modalidad_id')) {
            return;
        }

        Schema::table('expedientes_proceso', function (Blueprint $tabla) {
            $tabla->dropConstrainedForeignId('modalidad_id');
        });
    }
};
