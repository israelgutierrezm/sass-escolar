<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Models\Identidad\Usuario;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Response;

/**
 * Lo mínimo para que exista un alumno inscrito.
 *
 * ── Por qué hace falta tanto ───────────────────────────────────────────────
 * Una matrícula cuelga de una oferta, que cuelga de un programa académico, un plan y un
 * campus, que a su vez cuelgan de una institución y de catálogos con llaves
 * foráneas reales. No es ceremonia: es el esquema el que impide inventarse un
 * alumno suelto, y eso está bien —lo que estaría mal es que cada prueba lo
 * rearmara a mano—.
 *
 * Se escribe con el query builder y no con los modelos porque aquí lo único
 * que importa es que las filas existan y cuadren las llaves; las reglas de cada
 * modelo son justo lo que las pruebas van a ejercitar aparte.
 */
trait CreaEscuelaDePrueba
{
    /**
     * Una matrícula de alumno lista para usar, con toda su cadena detrás.
     *
     * @return array{persona: int, matricula: int, oferta: int, plan: int, programa académico: int, campus: int}
     */
    protected function alumnoInscrito(): array
    {
        $unico = uniqid();

        $institucion = $this->fila('instituciones', ['clave' => "INS-{$unico}", 'nombre' => 'Institución de prueba']);
        $campus = $this->fila('campus', ['clave' => "CAM-{$unico}", 'nombre' => 'Campus de prueba', 'institucion_id' => $institucion]);

        $nivel = $this->nivelDePrueba();

        $programaAcademico = $this->fila('programas_academicos', [
            'identificador' => "ID-{$unico}",
            'clave' => "CAR-{$unico}",
            'nombre' => 'Programa académico de prueba',
            'nivel_estudios_id' => $nivel,
        ]);

        $plan = $this->fila('planes_estudio', [
            'programa_academico_id' => $programaAcademico,
            'clave' => "PLA-{$unico}",
            'nombre' => 'Plan de prueba',
            'rvoe' => 'RVOE-000',
            'autorizacion_reconocimiento_id' => $this->deCatalogo('autorizaciones_reconocimiento'),
            'tipo_periodo_id' => $this->deCatalogo('tipos_periodo'),
            'calificacion_minima' => 0,
            'calificacion_maxima' => 10,
            'calificacion_minima_aprobatoria' => 6,
            'minimo_creditos' => 0,
        ]);

        $oferta = $this->fila('oferta', [
            'programa_academico_id' => $programaAcademico,
            'plan_id' => $plan,
            'campus_id' => $campus,
            'estatus' => 'activa',
        ]);

        $persona = $this->fila('personas', ['nombre' => 'Alumno', 'primer_apellido' => 'De prueba']);

        $matricula = $this->fila('matricula_oferta', [
            'persona_id' => $persona,
            'oferta_id' => $oferta,
            'matricula' => "MAT-{$unico}",
            'fecha_ingreso' => '2026-01-01',
            'situacion_id' => $this->deCatalogo('situaciones_alumno'),
            'estatus' => 'activo',
        ]);

        // Explícito y no `compact()`: la CLAVE va en snake —es lo que leen las
        // pruebas— y la variable en camel, como el resto del código.
        return [
            'persona' => $persona,
            'matricula' => $matricula,
            'oferta' => $oferta,
            'plan' => $plan,
            'programa_academico' => $programaAcademico,
            'campus' => $campus,
        ];
    }

    /**
     * Una materia abierta en un grupo, lista para inscribir a alguien.
     *
     * @param  int  $plan  El plan del alumno; la materia tiene que ser de ahí.
     * @return array{materia: int, planMateria: int, grupo: int, asignatura: int}
     */
    protected function materiaAbierta(int $plan, int $campus, int $ciclo, int $cupo = 30): array
    {
        $unico = uniqid();

        $asignatura = $this->fila('asignaturas', [
            'identificador' => "ASI-{$unico}",
            'clave' => "A-{$unico}",
            'nombre' => 'Materia de prueba',
            'creditos' => 8,
            'tipo_asignatura_id' => $this->deCatalogo('tipos_asignatura'),
        ]);

        $planMateria = $this->fila('plan_materias', [
            'plan_id' => $plan,
            'asignatura_id' => $asignatura,
            'clave_en_plan' => "PM-{$unico}",
        ]);

        $grupo = $this->fila('grupos', [
            'ciclo_id' => $ciclo,
            'campus_id' => $campus,
            'nivel_estudios_id' => $this->nivelDePrueba(),
            'semestre' => 1,
            'clave' => "G-{$unico}",
            'cupo' => $cupo,
            'situacion_id' => $this->deCatalogo('situaciones_grupo'),
        ]);

        $materia = $this->fila('asignatura_grupo', [
            'grupo_id' => $grupo,
            'plan_materia_id' => $planMateria,
            'situacion_id' => $this->deCatalogo('situaciones_asignatura_grupo'),
        ]);

        return compact('materia', 'planMateria', 'grupo', 'asignatura');
    }

    /**
     * Una situación con una clave concreta —«baja», «aprobada»—, que es lo que
     * miran las reglas del sistema.
     */
    protected function situacionCon(string $tabla, string $clave): int
    {
        $id = DB::table($tabla)->where('clave', $clave)->value('id');

        return $id !== null
            ? (int) $id
            : $this->fila($tabla, ['clave' => $clave, 'nombre' => ucfirst($clave)]);
    }

    /** Un ciclo escolar cualquiera. */
    protected function cicloDePrueba(string $clave = 'CICLO'): int
    {
        return $this->fila('ciclos', [
            'clave' => $clave.'-'.uniqid(),
            'nombre' => 'Ciclo de prueba',
            'fecha_inicio' => '2026-01-01',
            'fecha_fin' => '2026-06-30',
            'situacion_id' => $this->deCatalogo('situaciones_ciclo'),
        ]);
    }

    /**
     * El primer id de un catálogo, sembrándolo si la base viene vacía.
     *
     * Los catálogos los siembran los seeders en una escuela real; en pruebas se
     * crea uno de paso porque lo que se ejercita nunca es su contenido, sólo la
     * llave foránea.
     */
    protected function deCatalogo(string $tabla): int
    {
        $id = DB::table($tabla)->value('id');

        if ($id !== null) {
            return (int) $id;
        }

        return $this->fila($tabla, ['clave' => 'prueba', 'nombre' => 'De prueba']);
    }

    /**
     * El nivel de estudios de la escuela de prueba.
     *
     * Se creaba en la base CENTRAL «porque es catálogo de la SEP», pero el
     * modelo `Academico\NivelEstudio` es TENANT desde que cada escuela
     * administra los suyos. El id que quedaba en `programas_academicos.nivel_estudios_id`
     * existía en la central y no en la escuela, así que `nivelEstudios`
     * resolvía a NULL en toda prueba: lo que se ejercía era el caso «programa académico
     * sin nivel», no el normal. Se notó al probar el token {NIVEL} de la
     * matrícula, que salía vacío sin motivo aparente.
     */
    protected function nivelDePrueba(): int
    {
        return (int) (DB::table('niveles_estudio')->value('id')
            ?? $this->fila('niveles_estudio', ['clave' => 'LIC', 'nombre' => 'Licenciatura', 'orden' => 1]));
    }

    /**
     * Un usuario con su rol activo acotado a ciertos campus.
     *
     * El alcance no lo da un permiso sino `persona_rol.campus_id`: «coordinador
     * del Campus Norte» es el mismo rol que el del Campus Centro, con distinto
     * alcance. Sin campus, el rol es global y ve la escuela entera.
     *
     * @param  array<int, int>  $campusIds  Vacío = alcance global.
     */
    protected function usuarioConAlcance(array $campusIds = [], string $rol = 'administrativo'): Usuario
    {
        $unico = uniqid();

        $persona = $this->fila('personas', ['nombre' => 'Staff', 'primer_apellido' => 'De prueba']);

        // `name` es la CLAVE del rol (la que usan los middleware de Spatie) y
        // `nombre` su etiqueta: la tabla es a la vez la de permisos y el
        // catálogo de dominio.
        $rolId = DB::table('roles')->where('name', $rol)->value('id')
            ?? $this->fila('roles', ['name' => $rol, 'nombre' => ucfirst($rol), 'guard_name' => 'web']);

        // Un rol sin campus es global; con varios, se ve la unión de todos.
        foreach ($campusIds === [] ? [null] : $campusIds as $campusId) {
            $this->fila('persona_rol', [
                'persona_id' => $persona,
                'rol_id' => $rolId,
                'campus_id' => $campusId,
                'activo' => true,
            ]);
        }

        $usuarioId = $this->fila('usuarios', [
            'persona_id' => $persona,
            'usuario' => "staff-{$unico}",
            'email' => "staff-{$unico}@prueba.mx",
            'password' => bcrypt('secreto'),
            'rol_activo_id' => $rolId,
        ]);

        return Usuario::findOrFail($usuarioId);
    }

    /**
     * Una petición que viaja con ese usuario, como la que arma el middleware.
     *
     * Va marcada como petición de Inertia para que la respuesta sea el JSON con
     * las props y no el HTML de arranque: lo que las pruebas miran son los datos
     * que la pantalla recibe, no su maquetación.
     */
    protected function peticionDe(Usuario $usuario, string $uri = '/', array $parametros = []): Request
    {
        $peticion = Request::create($uri, 'GET', $parametros);
        $peticion->setUserResolver(fn () => $usuario);
        $peticion->headers->set('X-Inertia', 'true');

        return $peticion;
    }

    /**
     * Las props que una pantalla de Inertia recibe.
     *
     * @return array<string, mixed>
     */
    protected function propsDe(Response $respuesta, Request $peticion): array
    {
        return $respuesta->toResponse($peticion)->getData(true)['props'] ?? [];
    }

    /** @param  array<string, mixed>  $datos */
    protected function fila(string $tabla, array $datos): int
    {
        return (int) DB::table($tabla)->insertGetId([
            ...$datos,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
