<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El módulo de Reportes: su registro y la bitácora de ejecuciones.
 *
 * ── La bitácora entra en la PRIMERA rebanada, no en la última ────────────
 * Es lo que después decide qué construir: qué reportes se usan de verdad, con
 * qué filtros, cuáles tardan y cuáles nadie abre. Si se deja para el final, el
 * día que haya que decidir si vale la pena un constructor de reportes no habrá
 * un solo dato con el que decidirlo — y la decisión se tomará a ojo.
 *
 * También es lo que permite contestar «¿quién sacó la lista con las CURP?»,
 * que en un sistema escolar es una pregunta que se acaba haciendo.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ejecuciones_reporte')) {
            Schema::create('ejecuciones_reporte', function (Blueprint $tabla) {
                $tabla->id();

                /*
                 * `reporte` es una CLAVE de código, sin foránea: apunta a una
                 * clase, no a una fila. Un reporte que se retire deja su
                 * historia legible en vez de llevársela.
                 */
                $tabla->string('reporte', 80);
                $tabla->foreignId('persona_id')->nullable()->constrained('personas');
                $tabla->string('formato', 20)->default('pantalla');
                $tabla->unsignedInteger('filas')->default(0);
                $tabla->unsignedInteger('milisegundos')->default(0);
                // Lo que se pidió, tal cual: es lo que dice si un reporte se usa
                // siempre con el mismo filtro —candidato a preset— o si cada
                // quien lo usa distinto.
                $tabla->json('filtros')->nullable();
                $tabla->json('columnas')->nullable();
                // Las que se le ocultaron por permiso: contesta «¿por qué mi
                // Excel no trae la CURP?» sin abrir el código.
                $tabla->json('columnas_omitidas')->nullable();
                $tabla->auditoria();

                $tabla->index(['reporte', 'created_at']);
            });
        }

        // El módulo, ENCENDIDO desde el principio: una escuela que estrena esto
        // no tiene por qué ir a buscar el interruptor para ver sus reportes.
        // Calcado de `2026_08_09_110000_registrar_modulos_biblioteca_y_servicios`.
        $moduloId = DB::table('modulos')->where('clave', 'reportes')->value('id');

        if ($moduloId === null) {
            $moduloId = DB::table('modulos')->insertGetId([
                'clave' => 'reportes',
                'nombre' => 'Reportes',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('modulos_activos')->updateOrInsert(
            ['modulo_id' => $moduloId],
            ['activo' => true, 'created_at' => now(), 'updated_at' => now()],
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('ejecuciones_reporte');

        $moduloId = DB::table('modulos')->where('clave', 'reportes')->value('id');

        if ($moduloId !== null) {
            DB::table('modulos_activos')->where('modulo_id', $moduloId)->delete();
            DB::table('modulos')->where('id', $moduloId)->delete();
        }
    }
};
