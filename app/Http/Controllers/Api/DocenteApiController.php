<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ControlEscolar\AsignaturaGrupo;
use App\Models\ControlEscolar\Inscripcion;
use App\Models\Identidad\Usuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * El portal del DOCENTE para la app móvil.
 *
 * ── Qué se sirve, y qué NO todavía ─────────────────────────────────────────
 * El NÚCLEO de lectura: las materias que imparte y, por cada una, quiénes son
 * sus alumnos. Los flujos de captura del portal web —pasar lista, calificar,
 * asentar el acta— son rebanadas posteriores; aquí no viajan.
 *
 * ── El alcance sale de la ASIGNACIÓN, no del permiso ni de la URL ───────────
 * `ver-mis-materias` deja entrar al portal; qué materias son suyas lo dice
 * `docente_asignatura_grupo`, y el filtro va por `docentes.persona_id` —NO por
 * `personas.id`: es la columna que cuelga de la tabla `docentes`, la trampa que
 * ya mordió en la web—. Una materia ajena responde 403.
 *
 * ── La faceta la fija `api.faceta:docente` ─────────────────────────────────
 * De él depende que el `Gate::before` de `ver-mis-materias` resuelva como
 * DOCENTE. Sin ese middleware, el permiso no tendría rol activo que comprobar.
 */
class DocenteApiController extends Controller
{
    /** Las materias que imparte, con su grupo, horario y cuántos alumnos. */
    public function materias(Request $peticion): JsonResponse
    {
        $personaId = $this->personaId($peticion);

        $materias = AsignaturaGrupo::query()
            ->with([
                'planMateria.asignatura:id,nombre',
                'planMateria.plan:id,nombre',
                'grupo:id,clave,ciclo_id,campus_id',
                'grupo.ciclo:id,clave,nombre',
                'grupo.campus:id,nombre',
                'horarios.aula:id,nombre',
                'actas',
            ])
            ->whereHas('docentes', fn ($q) => $q->where('docentes.persona_id', $personaId))
            ->get()
            ->map(fn (AsignaturaGrupo $ag) => [
                'id' => $ag->id,
                'materia' => $ag->planMateria?->asignatura?->nombre,
                'plan' => $ag->planMateria?->plan?->nombre,
                'grupo' => $ag->grupo?->clave,
                'campus' => $ag->grupo?->campus?->nombre,
                'ciclo' => $ag->grupo?->ciclo?->clave,
                // Su papel en ESTA materia: el adjunto captura pero no firma.
                'soy' => $ag->docentes->firstWhere('persona_id', $personaId)?->pivot?->tipo,
                'inscritos' => Inscripcion::query()->where('asignatura_grupo_id', $ag->id)->count(),
                'acta_cerrada' => $ag->actas->contains(fn ($a) => $a->situacion === 'cerrada'),
                'horarios' => $this->horarios($ag),
            ])
            ->sortBy('materia')
            ->values()
            ->all();

        return response()->json(['materias' => $materias]);
    }

    /** Una materia mía: su información y el roster de alumnos. */
    public function materia(Request $peticion, AsignaturaGrupo $asignaturaGrupo): JsonResponse
    {
        $personaId = $this->autorizarMateria($peticion, $asignaturaGrupo);

        $asignaturaGrupo->load([
            'planMateria.asignatura:id,nombre',
            'planMateria.plan:id,nombre',
            'grupo.ciclo:id,clave,nombre',
            'grupo.campus:id,nombre',
            'horarios.aula:id,nombre',
            'docentes.persona:id,nombre,primer_apellido,segundo_apellido',
        ]);

        $alumnos = Inscripcion::query()
            ->with([
                'matriculaOferta:id,persona_id,matricula',
                'matriculaOferta.persona:id,nombre,primer_apellido,segundo_apellido',
                'situacion:id,clave,nombre',
            ])
            ->where('asignatura_grupo_id', $asignaturaGrupo->id)
            ->get()
            ->sortBy(fn (Inscripcion $i) => $i->matriculaOferta?->persona?->nombreCompleto() ?? '')
            ->map(fn (Inscripcion $i) => [
                'matricula' => $i->matriculaOferta?->matricula,
                'nombre' => $i->matriculaOferta?->persona?->nombreCompleto(),
                'situacion' => $i->situacion?->nombre,
                'situacion_clave' => $i->situacion?->clave,
            ])
            ->values()
            ->all();

        return response()->json([
            'materia' => [
                'id' => $asignaturaGrupo->id,
                'nombre' => $asignaturaGrupo->planMateria?->asignatura?->nombre,
                'plan' => $asignaturaGrupo->planMateria?->plan?->nombre,
                'grupo' => $asignaturaGrupo->grupo?->clave,
                'campus' => $asignaturaGrupo->grupo?->campus?->nombre,
                'ciclo' => $asignaturaGrupo->grupo?->ciclo?->clave,
                'soy' => $asignaturaGrupo->docentes->firstWhere('persona_id', $personaId)?->pivot?->tipo,
            ],
            'horarios' => $this->horarios($asignaturaGrupo),
            // Los compañeros que comparten la materia, sin uno mismo.
            'companeros' => $asignaturaGrupo->docentes
                ->reject(fn ($d) => $d->persona_id === $personaId)
                ->map(fn ($d) => ['nombre' => $d->persona?->nombreCompleto(), 'tipo' => $d->pivot->tipo])
                ->values()
                ->all(),
            'alumnos' => $alumnos,
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function horarios(AsignaturaGrupo $ag): array
    {
        return $ag->horarios->map(fn ($h) => [
            'dia' => $h->dia_semana,
            'inicio' => substr((string) $h->hora_inicio, 0, 5),
            'fin' => substr((string) $h->hora_fin, 0, 5),
            'aula' => $h->aula?->nombre,
        ])->values()->all();
    }

    /**
     * La persona del usuario. Sin ella no hay a qué acotar, así que se cierra en
     * vez de mostrar todo.
     */
    private function personaId(Request $peticion): int
    {
        /** @var Usuario $usuario */
        $usuario = $peticion->user();

        return $usuario->persona_id
            ?? throw new AccessDeniedHttpException('Tu cuenta no está ligada a una persona.');
    }

    /**
     * Sólo se entra a una materia propia, y se comprueba contra la ASIGNACIÓN
     * —no el permiso—: el permiso dice que puede dar clase, la asignación en
     * qué materia. El filtro va por `docentes.persona_id`.
     */
    private function autorizarMateria(Request $peticion, AsignaturaGrupo $asignaturaGrupo): int
    {
        $personaId = $this->personaId($peticion);

        $esSuya = $asignaturaGrupo->docentes()
            ->where('docentes.persona_id', $personaId)
            ->exists();

        if (! $esSuya) {
            throw new AccessDeniedHttpException('Esa materia no es tuya.');
        }

        return $personaId;
    }
}
