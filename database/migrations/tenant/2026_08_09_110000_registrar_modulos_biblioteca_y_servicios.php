<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Da de alta los dos módulos nuevos del portal del alumno y los deja ENCENDIDOS.
 *
 * La regla general es que un módulo sin fila en `modulos_activos` está apagado
 * —se falla cerrado, ver `ModulosDeLaEscuela`—. Estos dos se encienden aquí a
 * propósito: son secciones que la escuela pidió, y llegar apagadas significaría
 * que nadie las ve hasta que alguien adivine que hay que ir a prenderlas. Se
 * apagan desde la administración cuando estorben.
 *
 * Idempotente por clave: la migración puede correr sobre una escuela que ya los
 * tenga sin duplicar nada ni volver a encender lo que alguien apagó.
 */
return new class extends Migration
{
    private const MODULOS = [
        'biblioteca' => 'Biblioteca digital',
        'servicios' => 'Solicitud de servicios',
    ];

    public function up(): void
    {
        foreach (self::MODULOS as $clave => $nombre) {
            $existente = DB::table('modulos')->where('clave', $clave)->value('id');

            if ($existente !== null) {
                continue;
            }

            $id = DB::table('modulos')->insertGetId([
                'clave' => $clave,
                'nombre' => $nombre,
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
    }

    public function down(): void
    {
        $ids = DB::table('modulos')->whereIn('clave', array_keys(self::MODULOS))->pluck('id');

        DB::table('modulos_activos')->whereIn('modulo_id', $ids)->delete();
        DB::table('modulos')->whereIn('id', $ids)->delete();
    }
};
