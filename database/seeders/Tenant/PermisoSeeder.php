<?php

declare(strict_types=1);

namespace Database\Seeders\Tenant;

use App\Models\Identidad\Rol;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Catálogo de permisos por dominio y su asignación a los roles base.
 *
 * Los permisos se conceden al rol MÁS GENERAL que deba tenerlos: como los
 * roles funcionales heredan de su faceta (ver Rol::permisosEfectivos), lo que
 * se da a `administrativo` lo tienen todos los administrativos, y cada rol
 * funcional solo agrega lo suyo. Así se evita repetir permisos.
 *
 * Idempotente. La escuela puede reasignar permisos desde la administración sin
 * tocar código.
 */
class PermisoSeeder extends Seeder
{
    /** Permisos por dominio. */
    private const PERMISOS = [
        'entidades' => ['ver-personas', 'crear-personas', 'editar-personas'],
        'admisiones' => ['ver-aspirantes', 'crear-aspirantes', 'editar-aspirantes', 'validar-expediente', 'convertir-aspirante', 'generar-matricula'],
        'control-escolar' => ['ver-alumnos', 'editar-alumnos', 'inscribir-alumnos', 'ver-kardex', 'capturar-calificaciones', 'asentar-acta', 'gestionar-ventanas-captura', 'pasar-lista', 'ver-grupos'],
        'academico' => ['ver-catalogo-academico', 'editar-catalogo-academico', 'abrir-grupos'],
        'finanzas' => ['ver-adeudos', 'registrar-pagos', 'condonar-adeudos', 'facturar'],
        'plataforma' => ['ver-configuracion', 'editar-configuracion', 'gestionar-usuarios', 'gestionar-roles'],
    ];

    /** Qué permisos concede cada rol, además de los que hereda de su padre. */
    private const ASIGNACIONES = [
        // Faceta administrativa: lo mínimo común a todo el personal.
        'administrativo' => ['ver-personas', 'ver-alumnos', 'ver-catalogo-academico', 'ver-grupos'],

        'director_general' => [
            'crear-personas', 'editar-personas', 'ver-aspirantes', 'editar-alumnos',
            'ver-kardex', 'ver-adeudos', 'condonar-adeudos', 'ver-configuracion',
            'editar-configuracion', 'gestionar-usuarios', 'gestionar-roles',
            'editar-catalogo-academico', 'abrir-grupos', 'inscribir-alumnos',
            'gestionar-ventanas-captura',
        ],
        'director_campus' => [
            'crear-personas', 'editar-personas', 'ver-aspirantes', 'editar-alumnos',
            'ver-kardex', 'ver-adeudos', 'abrir-grupos',
        ],
        'encargado_admisiones' => [
            'ver-aspirantes', 'crear-aspirantes', 'editar-aspirantes',
            'validar-expediente', 'convertir-aspirante', 'generar-matricula',
            'crear-personas',
        ],
        'auxiliar_admisiones' => ['ver-aspirantes', 'crear-aspirantes', 'editar-aspirantes'],
        'encargado_control_escolar' => [
            'editar-alumnos', 'inscribir-alumnos', 'ver-kardex',
            'capturar-calificaciones', 'asentar-acta', 'gestionar-ventanas-captura',
            'abrir-grupos', 'editar-catalogo-academico',
        ],
        // Captura pero NO firma: puede vaciar las hojas que entrega el docente
        // y es el titular quien asienta el acta.
        'auxiliar_control_escolar' => ['inscribir-alumnos', 'ver-kardex', 'capturar-calificaciones'],
        'encargado_finanzas' => ['ver-adeudos', 'registrar-pagos', 'condonar-adeudos', 'facturar'],
        'auxiliar_finanzas' => ['ver-adeudos', 'registrar-pagos'],

        // Docencia.
        // El alcance del docente NO lo da el permiso sino la asignación en
        // `docente_asignatura_grupo`: solo captura y firma las materias que
        // imparte, y firmar es exclusivo del titular.
        'docente' => ['ver-alumnos', 'ver-kardex', 'pasar-lista', 'capturar-calificaciones', 'asentar-acta', 'ver-grupos'],
        'coordinador_academia' => ['ver-catalogo-academico', 'abrir-grupos'],

        // Facetas no administrativas: su alcance se resuelve además por
        // pertenencia (un alumno solo ve SU kárdex), no solo por permiso.
        'alumno' => ['ver-kardex', 'ver-adeudos'],
        'aspirante' => [],
        'tutor_educativo' => ['ver-alumnos', 'ver-kardex'],
        'padre_familia' => ['ver-kardex', 'ver-adeudos'],
    ];

    public function run(): void
    {
        // Spatie cachea el catálogo de permisos en el store configurado
        // (database), así que sobrevive entre procesos: sin este olvido, un
        // permiso recién sembrado existe en la tabla pero NADIE lo ve hasta
        // que el caché expira. Se limpia antes y después de sembrar.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::PERMISOS as $permisos) {
            foreach ($permisos as $permiso) {
                Permission::findOrCreate($permiso, 'web');
            }
        }

        foreach (self::ASIGNACIONES as $clave => $permisos) {
            $rol = Rol::query()->where('name', $clave)->where('guard_name', 'web')->first();

            if ($rol === null) {
                continue; // el rol no está sembrado en esta escuela
            }

            $rol->syncPermissions($permisos);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
