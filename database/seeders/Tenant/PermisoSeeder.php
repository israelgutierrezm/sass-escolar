<?php

declare(strict_types=1);

namespace Database\Seeders\Tenant;

use App\Models\Identidad\Rol;
use App\Support\CatalogoPermisos;
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
    /** Qué permisos concede cada rol, además de los que hereda de su padre. */
    private const ASIGNACIONES = [
        // Faceta administrativa: lo mínimo común a todo el personal.
        'administrativo' => ['ver-personas', 'ver-alumnos', 'ver-catalogo-academico', 'ver-grupos'],

        'director_general' => [
            'crear-personas', 'editar-personas', 'ver-aspirantes', 'editar-alumnos',
            'ver-kardex', 'ver-adeudos', 'registrar-pagos', 'condonar-adeudos',
            'gestionar-planes-cobro', 'gestionar-emisores', 'facturar', 'ver-configuracion',
            'editar-configuracion', 'gestionar-usuarios', 'gestionar-roles',
            'editar-catalogo-academico', 'abrir-grupos', 'inscribir-alumnos',
            'gestionar-ventanas-captura', 'ver-docentes', 'gestionar-docentes',
            'gestionar-documentos',
            // Ver el sistema como lo ve otra persona. Solo direccion: es la
            // capacidad mas delicada y queda en bitacora.
            'suplantar-usuarios',
            'gestionar-formularios',
        ],
        'director_campus' => [
            'crear-personas', 'editar-personas', 'ver-aspirantes', 'editar-alumnos',
            'ver-kardex', 'ver-adeudos', 'abrir-grupos', 'ver-docentes',
        ],
        'encargado_admisiones' => [
            'ver-aspirantes', 'crear-aspirantes', 'editar-aspirantes',
            'validar-expediente', 'convertir-aspirante', 'generar-matricula',
            'crear-personas', 'gestionar-documentos', 'gestionar-formularios',
        ],
        'auxiliar_admisiones' => ['ver-aspirantes', 'crear-aspirantes', 'editar-aspirantes'],
        'encargado_control_escolar' => [
            'editar-alumnos', 'inscribir-alumnos', 'ver-kardex',
            'capturar-calificaciones', 'asentar-acta', 'gestionar-ventanas-captura',
            'abrir-grupos', 'editar-catalogo-academico',
            'ver-docentes', 'gestionar-docentes',
            // Matricula reingresos y segundas carreras de quien ya es alumno de
            // la casa. La entrada de aspirantes sigue siendo de admisiones.
            'generar-matricula',
            'gestionar-documentos',
        ],
        // Captura pero NO firma: puede vaciar las hojas que entrega el docente
        // y es el titular quien asienta el acta.
        'auxiliar_control_escolar' => ['inscribir-alumnos', 'ver-kardex', 'capturar-calificaciones'],
        'encargado_finanzas' => ['ver-adeudos', 'registrar-pagos', 'condonar-adeudos', 'facturar', 'gestionar-planes-cobro', 'gestionar-emisores'],
        'auxiliar_finanzas' => ['ver-adeudos', 'registrar-pagos'],

        // Docencia.
        // El alcance del docente NO lo da el permiso sino la asignación en
        // `docente_asignatura_grupo`: solo captura y firma las materias que
        // imparte, y firmar es exclusivo del titular.
        //
        // SIN `ver-grupos` ni `ver-alumnos`: esos son de personal
        // administrativo y le abrían Control escolar entero —ciclos y grupos de
        // toda la escuela— además de la futura pantalla de alumnos. El docente
        // llega a sus alumnos por sus materias, no por un listado global.
        'docente' => [
            'ver-mis-materias', 'editar-mi-expediente',
            'ver-kardex', 'pasar-lista', 'capturar-calificaciones', 'asentar-acta',
        ],
        'coordinador_academia' => ['ver-catalogo-academico', 'abrir-grupos', 'ver-docentes'],

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

        // El catálogo vive en App\Support\CatalogoPermisos y NO aquí: lo
        // consultan dos —este seeder al sembrar y la pantalla de roles al
        // pintar las casillas agrupadas por dominio—. Tenerlo dentro del
        // seeder dejaba esa agrupación invisible para la interfaz.
        foreach (CatalogoPermisos::claves() as $permiso) {
            Permission::findOrCreate($permiso, 'web');
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
