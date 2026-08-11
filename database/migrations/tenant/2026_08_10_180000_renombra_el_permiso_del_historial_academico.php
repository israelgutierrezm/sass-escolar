<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `ver-kardex` pasa a llamarse `ver-historial-academico`.
 *
 * ── Por qué se RENOMBRA y no se crea el nuevo ──────────────────────────────
 * Porque los roles no guardan el nombre del permiso: guardan su ID, en
 * `role_has_permissions`. Sembrar el nuevo con el seeder habría dado de alta
 * otra fila con otro id y dejado la vieja intacta, así que todos los roles
 * seguirían apuntando a `ver-kardex` —un permiso que ya ninguna ruta consulta— y
 * la escuela entera perdería el acceso al historial académico sin que nadie
 * pudiera explicar por qué. Un `UPDATE` del nombre conserva el id, y con él
 * todas las asignaciones.
 *
 * ── Se comprueba antes de actuar ───────────────────────────────────────────
 * Una escuela nueva ya nace con el nombre nuevo (lo siembra el seeder desde
 * `CatalogoPermisos`), así que aquí no habría nada que renombrar; y si por lo
 * que sea existieran los dos, renombrar chocaría contra el único de
 * `permissions`. En ese caso se conserva el NUEVO y se reapuntan a él las
 * asignaciones del viejo antes de retirarlo: lo que no se puede perder es qué
 * rol tenía el permiso.
 */
return new class extends Migration
{
    private const VIEJO = 'ver-kardex';

    private const NUEVO = 'ver-historial-academico';

    public function up(): void
    {
        $viejo = DB::table('permissions')->where('name', self::VIEJO)->first();

        if ($viejo === null) {
            return;
        }

        $nuevo = DB::table('permissions')->where('name', self::NUEVO)->first();

        if ($nuevo === null) {
            DB::table('permissions')->where('id', $viejo->id)->update(['name' => self::NUEVO]);

            return;
        }

        // Los dos existen: se traslada lo asignado y se retira el viejo.
        $yaLoTienen = DB::table('role_has_permissions')
            ->where('permission_id', $nuevo->id)
            ->pluck('role_id');

        DB::table('role_has_permissions')
            ->where('permission_id', $viejo->id)
            ->whereNotIn('role_id', $yaLoTienen)
            ->update(['permission_id' => $nuevo->id]);

        DB::table('role_has_permissions')->where('permission_id', $viejo->id)->delete();
        DB::table('permissions')->where('id', $viejo->id)->delete();
    }

    public function down(): void
    {
        DB::table('permissions')
            ->where('name', self::NUEVO)
            ->update(['name' => self::VIEJO]);
    }
};
