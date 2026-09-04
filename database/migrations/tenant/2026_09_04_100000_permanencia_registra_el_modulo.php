<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Da de alta el módulo de alertas tempranas y permanencia, ENCENDIDO.
 *
 * La regla general es que un módulo sin fila en `modulos_activos` está apagado
 * —se falla cerrado, ver `ModulosDeLaEscuela`—. Éste se enciende a propósito:
 * es una sección que la escuela pidió, y llegar apagada significaría que nadie
 * la ve hasta que alguien adivine que hay que ir a prenderla.
 *
 * ── La clave es `permanencia` y no `alertas` ───────────────────────────────
 * Las alertas son el principio de esto, no lo que es: el módulo entrega el
 * ciclo completo hasta el cierre de un caso, y una clave que nombrara sólo la
 * primera mitad envejecería mal — es la misma razón por la que el módulo
 * formativo no se llamó `servicio_social`.
 *
 * Y **permanencia y no deserción**: lo que se persigue es que el alumno se
 * quede, no clasificar a quien se va. La palabra que se elige aquí es la que
 * después aparece en el menú, en los reportes y en las conversaciones.
 *
 * Idempotente por clave: puede correr sobre una escuela que ya lo tenga sin
 * duplicar nada ni volver a encender lo que alguien apagó.
 */
return new class extends Migration
{
    private const CLAVE = 'permanencia';

    private const NOMBRE = 'Alertas tempranas y permanencia';

    public function up(): void
    {
        if (DB::table('modulos')->where('clave', self::CLAVE)->exists()) {
            return;
        }

        $id = DB::table('modulos')->insertGetId([
            'clave' => self::CLAVE,
            'nombre' => self::NOMBRE,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('modulos_activos')->insert([
            'modulo_id' => $id,
            'activo' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        $id = DB::table('modulos')->where('clave', self::CLAVE)->value('id');

        if ($id === null) {
            return;
        }

        DB::table('modulos_activos')->where('modulo_id', $id)->delete();
        DB::table('modulos')->where('id', $id)->delete();
    }
};
