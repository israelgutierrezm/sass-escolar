<?php

declare(strict_types=1);

namespace Tests\Concerns;

use Illuminate\Support\Facades\DB;

/**
 * Lo mínimo para que exista un alumno inscrito.
 *
 * ── Por qué hace falta tanto ───────────────────────────────────────────────
 * Una matrícula cuelga de una oferta, que cuelga de una carrera, un plan y un
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
     * @return array{persona: int, matricula: int, oferta: int, plan: int, carrera: int, campus: int}
     */
    protected function alumnoInscrito(): array
    {
        $unico = uniqid();

        $institucion = $this->fila('instituciones', ['clave' => "INS-{$unico}", 'nombre' => 'Institución de prueba']);
        $campus = $this->fila('campus', ['clave' => "CAM-{$unico}", 'nombre' => 'Campus de prueba', 'institucion_id' => $institucion]);

        // Los niveles de estudio viven en la base CENTRAL: son catálogo de la
        // SEP, iguales para todas las escuelas.
        $nivel = DB::connection('central')->table('niveles_estudio')->value('id')
            ?? DB::connection('central')->table('niveles_estudio')->insertGetId([
                'clave' => 'LIC', 'nombre' => 'Licenciatura', 'orden' => 1,
            ]);

        $carrera = $this->fila('carreras', [
            'identificador' => "ID-{$unico}",
            'clave' => "CAR-{$unico}",
            'nombre' => 'Carrera de prueba',
            'nivel_estudios_id' => $nivel,
        ]);

        $plan = $this->fila('planes_estudio', [
            'carrera_id' => $carrera,
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
            'carrera_id' => $carrera,
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

        return compact('persona', 'matricula', 'oferta', 'plan', 'carrera', 'campus');
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
            'nivel_estudios_id' => DB::connection('central')->table('niveles_estudio')->value('id'),
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
