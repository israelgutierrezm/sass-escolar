<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Retira de la base lo que se dejó de declarar en el código.
 *
 * Salió de auditar qué está declarado y nadie usa. Los dos casos son la misma
 * clase de problema —algo que se ofrece y no hace nada— y por eso se van juntos.
 *
 * ── `alumno.matricula_unica_por_persona` ──────────────────────────────────
 * Prometía que quien cursa dos programas conserve el MISMO número de matrícula
 * en ambos. No se puede: `matricula_oferta.matricula` tiene índice ÚNICO, así
 * que dos filas no pueden compartir número. Nadie lo leía nunca, de modo que
 * encenderlo no hacía nada —pero se veía en la pantalla de configuración como
 * una regla disponible—.
 *
 * Cumplirlo exigiría tirar ese único, y eso no es un ajuste: la matrícula
 * dejaría de identificar una fila y toda búsqueda por matrícula se volvería
 * ambigua. Va en contra de la decisión de arquitectura de que el alumno ES la
 * matrícula. Si algún día se quiere, es un cambio de esquema con consecuencias,
 * no una casilla.
 *
 * ── El permiso `crear-personas` ────────────────────────────────────────────
 * Ninguna ruta lo comprobaba. Una persona nunca se crea sola: nace dentro del
 * alta de un aspirante, un alumno, un docente, un tutor o un usuario, y cada una
 * de ésas ya tiene su permiso. Se palomeaba creyendo que concedía algo.
 *
 * Se comprueba que NINGÚN rol lo tenga antes de borrarlo: si alguna escuela se
 * lo hubiera asignado, borrarlo le cambiaría la definición de un rol por la
 * espalda, y eso se avisa en vez de hacerse.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('configuraciones')->where('clave', 'alumno.matricula_unica_por_persona')->delete();

        $permiso = DB::table('permissions')->where('name', 'crear-personas')->first();

        if ($permiso === null) {
            return;
        }

        $asignado = DB::table('role_has_permissions')->where('permission_id', $permiso->id)->count()
            + DB::table('model_has_permissions')->where('permission_id', $permiso->id)->count();

        if ($asignado > 0) {
            // No se toca lo que alguien está usando, aunque no sirva: eso es
            // decisión de la escuela, no de una migración.
            echo "  `crear-personas` sigue asignado a {$asignado} rol(es): NO se borró.".PHP_EOL;

            return;
        }

        DB::table('permissions')->where('id', $permiso->id)->delete();
    }

    public function down(): void
    {
        /*
         * No se reponen. El ajuste no se podía cumplir y el permiso no abría
         * ninguna puerta: devolverlos sería volver a ofrecer dos cosas que no
         * hacen nada.
         */
    }
};
