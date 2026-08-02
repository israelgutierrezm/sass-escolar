<?php

declare(strict_types=1);

namespace App\Services\Plataforma;

use App\Models\Admisiones\MatriculaOferta;
use App\Models\ControlEscolar\AsignaturaGrupo;
use App\Models\ControlEscolar\Inscripcion;
use App\Models\Identidad\Persona;

/**
 * Dónde está parada una persona dentro de la escuela.
 *
 * A qué campus pertenece, qué carrera cursa o imparte, en qué grupos está, qué
 * materias le tocan. Es lo que hace falta para contestar «¿este aviso es para
 * mí?» sin que quien lo escribió tenga que enumerar personas.
 *
 * ── Por qué un servicio y no un método en Persona ──────────────────────────
 * La respuesta depende del OFICIO: un alumno pertenece a un campus por su
 * matrícula, y un docente por las materias que le asignaron. Son dos consultas
 * distintas que terminan en la misma pregunta, y meterlas en el modelo lo
 * habría llenado de ramas por rol.
 *
 * ── Una sola pasada ────────────────────────────────────────────────────────
 * Todo se resuelve junto y se guarda en memoria: la agenda se arma para un
 * rango de fechas y no puede permitirse una consulta por criterio por evento.
 */
class ContextoAcademico
{
    /** @var array<int, array<string, array<int, int>>> */
    private array $cache = [];

    /**
     * Los ids a los que pertenece la persona, por criterio.
     *
     * @return array{campus: int[], nivel: int[], carrera: int[], plan: int[], grupo: int[], materia: int[]}
     */
    public function de(?int $personaId): array
    {
        if ($personaId === null) {
            return $this->vacio();
        }

        return $this->cache[$personaId] ??= $this->resolver($personaId);
    }

    /**
     * @return array{campus: int[], nivel: int[], carrera: int[], plan: int[], grupo: int[], materia: int[]}
     */
    private function resolver(int $personaId): array
    {
        $persona = Persona::find($personaId);

        if ($persona === null) {
            return $this->vacio();
        }

        $comoAlumno = $this->comoAlumno($personaId);
        $comoDocente = $this->comoDocente($personaId);

        // Se unen los dos oficios: hay quien da clase y además estudia un
        // posgrado en la misma escuela, y le tocan los avisos de ambos lados.
        return [
            'campus' => $this->unir($comoAlumno['campus'], $comoDocente['campus']),
            'nivel' => $this->unir($comoAlumno['nivel'], $comoDocente['nivel']),
            'carrera' => $this->unir($comoAlumno['carrera'], $comoDocente['carrera']),
            'plan' => $this->unir($comoAlumno['plan'], $comoDocente['plan']),
            'grupo' => $this->unir($comoAlumno['grupo'], $comoDocente['grupo']),
            'materia' => $this->unir($comoAlumno['materia'], $comoDocente['materia']),
        ];
    }

    /**
     * Lo que le toca por estar inscrito.
     *
     * El campus, la carrera y el plan salen de la OFERTA de su matrícula —que
     * es donde vive esa combinación—; los grupos y materias, de sus
     * inscripciones del ciclo.
     *
     * @return array<string, int[]>
     */
    private function comoAlumno(int $personaId): array
    {
        /*
         * Por el modelo y no con los nombres de tabla a mano: `oferta` es
         * singular y `planes_estudio` no se llama como su modelo. Escribirlos
         * aquí sería repetir un dato que ya vive en un solo lugar —y ya costó
         * un «table doesn't exist» al construir esto—.
         */
        $matriculas = MatriculaOferta::query()
            ->where('persona_id', $personaId)
            ->with(['oferta:id,campus_id,carrera_id,plan_id', 'oferta.carrera:id,nivel_estudios_id'])
            ->get(['id', 'oferta_id']);

        if ($matriculas->isEmpty()) {
            return $this->vacio();
        }

        $inscripciones = Inscripcion::query()
            ->whereIn('matricula_oferta_id', $matriculas->pluck('id'))
            ->with('asignaturaGrupo:id,grupo_id')
            ->get(['id', 'asignatura_grupo_id']);

        return [
            'campus' => $matriculas->pluck('oferta.campus_id')->filter()->unique()->values()->all(),
            'nivel' => $matriculas->pluck('oferta.carrera.nivel_estudios_id')->filter()->unique()->values()->all(),
            'carrera' => $matriculas->pluck('oferta.carrera_id')->filter()->unique()->values()->all(),
            'plan' => $matriculas->pluck('oferta.plan_id')->filter()->unique()->values()->all(),
            'grupo' => $inscripciones->pluck('asignaturaGrupo.grupo_id')->filter()->unique()->values()->all(),
            'materia' => $inscripciones->pluck('asignatura_grupo_id')->filter()->unique()->values()->all(),
        ];
    }

    /**
     * Lo que le toca por dar clase.
     *
     * Se entra por `docente_asignatura_grupo` —la asignación—, y de ahí se sube
     * al grupo para saber campus y plan. Un docente no tiene «su» carrera: se le
     * atribuyen las de los grupos donde imparte.
     *
     * @return array<string, int[]>
     */
    private function comoDocente(int $personaId): array
    {
        $materias = AsignaturaGrupo::query()
            ->whereHas('docentes', fn ($q) => $q->where('docentes.persona_id', $personaId))
            ->with([
                'grupo:id,campus_id,plan_id',
                'grupo.plan:id,carrera_id',
                'grupo.plan.carrera:id,nivel_estudios_id',
            ])
            ->get(['id', 'grupo_id']);

        if ($materias->isEmpty()) {
            return $this->vacio();
        }

        return [
            'campus' => $materias->pluck('grupo.campus_id')->filter()->unique()->values()->all(),
            'nivel' => $materias->pluck('grupo.plan.carrera.nivel_estudios_id')->filter()->unique()->values()->all(),
            'carrera' => $materias->pluck('grupo.plan.carrera_id')->filter()->unique()->values()->all(),
            'plan' => $materias->pluck('grupo.plan_id')->filter()->unique()->values()->all(),
            'grupo' => $materias->pluck('grupo_id')->filter()->unique()->values()->all(),
            'materia' => $materias->pluck('id')->values()->all(),
        ];
    }

    /**
     * @param  int[]  $a
     * @param  int[]  $b
     * @return int[]
     */
    private function unir(array $a, array $b): array
    {
        return array_values(array_unique([...$a, ...$b]));
    }

    /**
     * @return array{campus: int[], nivel: int[], carrera: int[], plan: int[], grupo: int[], materia: int[]}
     */
    private function vacio(): array
    {
        return ['campus' => [], 'nivel' => [], 'carrera' => [], 'plan' => [], 'grupo' => [], 'materia' => []];
    }
}
