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
    /** Marca: este rol recibe TODOS los permisos de su faceta. */
    private const TODOS_LOS_DE_SU_FACETA = ['*'];

    /** Qué permisos concede cada rol, además de los que hereda de su padre. */
    private const ASIGNACIONES = [
        // Faceta administrativa: lo mínimo común a todo el personal.
        'administrativo' => ['ver-personas', 'ver-alumnos', 'ver-catalogo-academico', 'ver-grupos'],

        // Dirección general se DERIVA del catálogo: todos los permisos de su
        // faceta, sin lista a mano. Una lista escrita a mano se queda vieja
        // cada vez que se agrega un permiso —fue exactamente lo que pasó con
        // `ver-mis-prospectos`, y produjo un 403 que nadie sabía explicar—.
        // Ver `permisosDe()` más abajo.
        'director_general' => self::TODOS_LOS_DE_SU_FACETA,

        // Las variantes administrativas (director de campus, encargados,
        // auxiliares, promotor, coordinador de academia) YA NO se siembran:
        // las crea cada escuela desde /plataforma/roles y ahí les palomea sus
        // permisos —siempre acotados a la faceta administrativa—. Por eso aquí
        // solo quedan la faceta base y `director_general` (que se lleva todo).

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
            'ver-mis-materias', 'editar-mi-expediente', 'editar-mi-disponibilidad',
            'ver-historial-academico', 'pasar-lista', 'capturar-calificaciones', 'asentar-acta',
            'levantar-incidencia',
        ],

        // Facetas no administrativas: su alcance se resuelve además por
        // pertenencia (un alumno solo ve SU historial académico), no solo por permiso.
        // El alumno traía solo dos permisos de consulta y NINGUNA pantalla
        // propia: podía entrar al sistema y no tenía a dónde ir. `ver-mis-cursos`
        // le abre su portal; el alcance —sus propias matrículas— lo resuelve el
        // controlador, igual que en el portal del padre.
        // `editar-mi-expediente-alumno` es el suyo, no el del docente: son dos
        // expedientes distintos y quien enseña y además estudia no debe heredar
        // uno del otro rol.
        // `ver-biblioteca` y `solicitar-servicios` son las dos secciones que
        // sólo se alcanzan desde el panel. Sin ellas aquí, la tarjeta no sale y
        // el alumno no tiene por dónde llegar —es el mismo olvido que el
        // comentario de arriba describe con `ver-mis-prospectos`, y se repitió
        // al agregar estas dos.
        'alumno' => [
            'ver-mis-cursos', 'editar-mi-expediente-alumno', 'ver-historial-academico', 'ver-adeudos',
            'ver-biblioteca', 'solicitar-servicios', 'ver-vacantes',
        ],
        // El interesado llena lo suyo desde `/mi-solicitud`. No ve nada más:
        // su alcance es su propia persona, no un permiso amplio.
        'aspirante' => ['llenar-mi-solicitud'],
        // SIN `ver-alumnos`: le abría el listado de TODA la escuela —y el
        // historial académico de cualquiera— porque no había vínculo por el cual acotarlo.
        // Ahora lo hay, en `tutorias`, y su portal resuelve el alcance por
        // pertenencia igual que el del padre y el del docente.
        'tutor_educativo' => ['ver-mis-tutorados', 'ver-historial-academico'],
        'padre_familia' => ['ver-mis-hijos', 'ver-historial-academico', 'ver-adeudos', 'editar-mi-expediente-tutor', 'ver-conducta-hijo'],
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

            $rol->syncPermissions($this->resolver($rol, $permisos));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Expande la marca `*` a todos los permisos de la faceta del rol, y filtra
     * lo que no le corresponda.
     *
     * El filtro no es paranoia: un permiso puesto a mano en la lista que
     * pertenezca a otro oficio rompería la separación que el sistema sostiene,
     * y este seeder corre en cada escuela.
     *
     * @param  array<int, string>  $permisos
     * @return array<int, string>
     */
    private function resolver(Rol $rol, array $permisos): array
    {
        $ambito = $rol->ambitoDePermisos();

        if ($permisos === self::TODOS_LOS_DE_SU_FACETA) {
            return CatalogoPermisos::clavesDe($ambito);
        }

        return array_values(array_filter(
            $permisos,
            fn (string $p) => CatalogoPermisos::correspondeA($p, $ambito)
        ));
    }
}
