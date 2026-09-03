<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Da de alta el módulo de servicio social y prácticas, y lo deja ENCENDIDO.
 *
 * La regla general es que un módulo sin fila en `modulos_activos` está apagado
 * —se falla cerrado, ver `ModulosDeLaEscuela`—. Éste se enciende a propósito:
 * es una sección que la escuela pidió, y llegar apagada significaría que nadie
 * la ve hasta que alguien adivine que hay que ir a prenderla. Se apaga desde
 * `/plataforma/modulos` cuando estorbe.
 *
 * ── La clave es `procesos_formativos` y la etiqueta no ─────────────────────
 * La sección se llama «Servicio social y prácticas» porque así se llama la
 * oficina, pero el catálogo de tipos trae ocho —residencia, estancia,
 * internado, proyecto comunitario…— y una clave que enumerara dos de ellos
 * envejecería mal.
 *
 * Idempotente por clave: puede correr sobre una escuela que ya lo tenga sin
 * duplicar nada ni volver a encender lo que alguien apagó.
 */
return new class extends Migration
{
    private const CLAVE = 'procesos_formativos';

    private const NOMBRE = 'Servicio social y prácticas';

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
