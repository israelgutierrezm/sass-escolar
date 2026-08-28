<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Retira `ver-personas`, que no abría ninguna puerta.
 *
 * ── Qué prometía y por qué no se cumple ───────────────────────────────────
 * «Consultar el directorio de personas de la escuela». Ese directorio NO EXISTE
 * —ni existía cuando se declaró el permiso—: no hay ruta, ni pantalla, ni
 * tarjeta de panel, ni entrada de menú que lo comprobara. Se palomeaba en
 * `/plataforma/roles` creyendo que concedía algo.
 *
 * Y tampoco debería existir. A una persona no se la consulta en abstracto: se
 * la consulta como alumna, docente, prospecto, tutora o cuenta, y cada uno de
 * esos listados tiene su permiso Y su alcance —por campus, por asignación—. Un
 * directorio plano sería la puerta que se salta todos esos alcances a la vez.
 *
 * Es el segundo de la misma familia; el primero fue `crear-personas`, retirado
 * en `2026_08_20_100000_retira_ajustes_y_permisos_sin_uso`.
 *
 * ── Lo que este caso tiene de distinto ────────────────────────────────────
 * Aquél no lo tenía asignado nadie, así que bastaba con borrarlo. Éste lo
 * concede el propio `PermisoSeeder` a la faceta administrativa, y de ahí lo
 * hereda dirección general: en TODA escuela hay al menos dos roles con él. La
 * salvaguarda de «no lo borro si alguien lo usa» rehusaría siempre, y el
 * permiso se quedaría para siempre en una tabla desde la que ya no se puede ni
 * ver —porque el catálogo dejó de declararlo—.
 *
 * Así que la línea se traza por quién lo asignó:
 *
 *   - Lo que sembramos NOSOTROS —roles con `protegido`— se revoca: es
 *     deshacer nuestro propio acto, no el de la escuela.
 *   - Lo que la escuela haya decidido —cualquier rol no protegido, o una
 *     persona con el permiso directo— NO se toca, y entonces la fila se
 *     conserva y se avisa. Cambiarle la definición de un rol a alguien por la
 *     espalda es peor que dejar una fila muerta.
 */
return new class extends Migration
{
    public function up(): void
    {
        $permiso = DB::table('permissions')->where('name', 'ver-personas')->first();

        if ($permiso === null) {
            return;
        }

        // Lo que puso el seeder: los roles protegidos son los seis de base.
        DB::table('role_has_permissions')
            ->whereIn('role_id', DB::table('roles')->where('protegido', true)->pluck('id'))
            ->where('permission_id', $permiso->id)
            ->delete();

        $deLaEscuela = DB::table('role_has_permissions')->where('permission_id', $permiso->id)->count()
            + DB::table('model_has_permissions')->where('permission_id', $permiso->id)->count();

        if ($deLaEscuela > 0) {
            echo "  `ver-personas` sigue asignado en {$deLaEscuela} sitio(s) que decidió la escuela: NO se borró.".PHP_EOL;

            return;
        }

        DB::table('permissions')->where('id', $permiso->id)->delete();
    }

    public function down(): void
    {
        /*
         * No se repone. El permiso no abría ninguna puerta, así que devolverlo
         * sería volver a ofrecer algo que no hace nada — y quien lo palomeara
         * seguiría creyendo que concede acceso a un directorio que no existe.
         */
    }
};
