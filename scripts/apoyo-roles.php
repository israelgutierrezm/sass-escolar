<?php

declare(strict_types=1);

/**
 * Un rol funcional para las pruebas, plantado si la escuela lo borró.
 *
 * ── Por qué hace falta esto ────────────────────────────────────────────────
 * Los roles funcionales de ejemplo —encargado de control escolar, director de
 * campus, promotor, encargado de finanzas— son BORRABLES por diseño. Es una
 * regla escrita del proyecto: «los roles de ejemplo deben poder borrarse», y la
 * escuela demo ejerció ese derecho.
 *
 * Seis suites los buscaban con `firstOrFail()` y se caían con
 * «No query results for model [Rol]». No fallaba lo que probaban: fallaba una
 * suposición sobre datos que nadie prometió conservar. Una prueba que depende de
 * un dato que el usuario puede borrar desde la pantalla es una prueba que
 * caduca sola.
 *
 * Ahora cada suite planta el rol que necesita dentro de SU transacción, así que
 * el rollback se lo lleva y la escuela demo queda como estaba.
 *
 * ── Las facetas sí se pueden dar por hechas ────────────────────────────────
 * `administrativo`, `docente`, `alumno`, `aspirante`, `tutor_educativo` y
 * `padre_familia` llevan `protegido` y su clave no se toca —hay código que las
 * conoce por nombre—, así que colgar de ellas no es una suposición frágil.
 *
 * Se incluye con `require` desde cada suite:
 *
 *     require __DIR__.'/apoyo-roles.php';
 */

use App\Models\Identidad\Rol;

if (! function_exists('rolFuncionalDePrueba')) {
    /**
     * El rol con esa clave; si no existe, se crea colgando de la faceta.
     *
     * Los permisos se declaran en la llamada y no se heredan del seeder: si el
     * rol es de la escuela, sus permisos también, y una prueba no puede
     * depender de cuáles le tocaron el día que se sembró.
     *
     * @param  array<int, string>  $permisos  permisos PROPIOS del rol (los de
     *                                        su faceta los hereda igual)
     */
    function rolFuncionalDePrueba(string $clave, string $faceta, array $permisos = []): Rol
    {
        $rol = Rol::where('name', $clave)->first();

        if ($rol !== null) {
            return $rol;
        }

        $rol = Rol::create([
            'name' => $clave,
            'nombre' => ucfirst(str_replace('_', ' ', $clave)),
            'guard_name' => 'web',
            'rol_padre_id' => Rol::where('name', $faceta)->value('id'),
            'protegido' => false,
        ]);

        if ($permisos !== []) {
            $rol->syncPermissions($permisos);
        }

        return $rol;
    }
}
