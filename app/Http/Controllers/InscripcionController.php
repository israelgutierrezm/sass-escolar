<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Admisiones\MatriculaOferta;
use App\Models\ControlEscolar\AsignaturaGrupo;
use App\Models\ControlEscolar\Ciclo;
use App\Models\ControlEscolar\Inscripcion;
use App\Models\ControlEscolar\SituacionInscripcion;
use App\Services\ValidadorInscripcion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Inscripción de alumnos a materias.
 *
 * `inscripcion` es el nivel único del sistema: una fila = un alumno (por su
 * matrícula en una oferta) en UNA materia-grupo. Inscribir "a todo el grupo"
 * son N filas; una materia suelta es una; un recursamiento es la misma tabla
 * con `tipo` distinto.
 *
 * Las reglas viven en ValidadorInscripcion y se aplican tanto aquí como en la
 * futura inscripción autogestiva del alumno.
 */
class InscripcionController extends Controller
{
    public function index(Request $request, ValidadorInscripcion $validador): Response
    {
        $matricula = $this->matriculaSeleccionada($request);
        $ciclo = $this->cicloSeleccionado($request);

        return Inertia::render('ControlEscolar/Inscripciones/Index', [
            'alumnos' => MatriculaOferta::query()
                ->with(['persona:id,nombre,primer_apellido,segundo_apellido', 'oferta.carrera:id,nombre'])
                ->where('estatus', 'activo')
                ->orderBy('matricula')
                ->get()
                ->map(fn (MatriculaOferta $m) => [
                    'id' => $m->id,
                    'etiqueta' => sprintf(
                        '%s · %s (%s)',
                        $m->matricula,
                        $m->persona?->nombreCompleto() ?? '',
                        $m->oferta?->carrera?->nombre ?? 'sin carrera',
                    ),
                ]),
            'ciclos' => Ciclo::query()
                ->orderByDesc('fecha_inicio')
                ->get(['id', 'clave', 'nombre'])
                ->map(fn (Ciclo $c) => ['id' => $c->id, 'etiqueta' => "{$c->clave} — {$c->nombre}"]),
            'seleccion' => [
                'matricula_oferta_id' => $matricula?->id,
                'ciclo_id' => $ciclo?->id,
            ],
            'alumno' => $matricula === null ? null : [
                'matricula' => $matricula->matricula,
                'nombre' => $matricula->persona?->nombreCompleto(),
                'carrera' => $matricula->oferta?->carrera?->nombre,
                'plan' => $matricula->oferta?->plan?->nombre,
            ],
            'inscritas' => $this->inscritas($matricula, $ciclo),
            'disponibles' => $this->disponibles($matricula, $ciclo, $validador),
            'puedeInscribir' => $request->user()->can('inscribir-alumnos'),
        ]);
    }

    public function store(Request $request, ValidadorInscripcion $validador): RedirectResponse
    {
        $datos = $request->validate([
            'matricula_oferta_id' => ['required', 'integer', Rule::exists('matricula_oferta', 'id')->whereNull('deleted_at')],
            'asignatura_grupo_id' => ['required', 'integer', Rule::exists('asignatura_grupo', 'id')->whereNull('deleted_at')],
            'tipo' => ['required', Rule::in([Inscripcion::TIPO_ORDINARIA, Inscripcion::TIPO_RECURSAMIENTO])],
        ], [], [
            'matricula_oferta_id' => 'alumno',
            'asignatura_grupo_id' => 'materia',
        ]);

        $matricula = MatriculaOferta::findOrFail($datos['matricula_oferta_id']);
        $materiaGrupo = AsignaturaGrupo::with('grupo')->findOrFail($datos['asignatura_grupo_id']);

        // Se revalida en el servidor aunque la interfaz ya haya filtrado: el
        // estado pudo cambiar entre que se pintó la pantalla y se envió.
        $impedimentos = $validador->impedimentos($matricula, $materiaGrupo);

        if ($impedimentos !== []) {
            throw ValidationException::withMessages([
                'asignatura_grupo_id' => implode(' ', $impedimentos),
            ]);
        }

        Inscripcion::create([
            'matricula_oferta_id' => $matricula->id,
            'asignatura_grupo_id' => $materiaGrupo->id,
            'ciclo_id' => $materiaGrupo->grupo->ciclo_id,
            'tipo' => $datos['tipo'],
            'forma_inscripcion' => Inscripcion::FORMA_ADMINISTRATIVA,
            'situacion_id' => SituacionInscripcion::query()->where('clave', 'inscrito')->value('id'),
        ]);

        return back()->with('exito', 'Alumno inscrito.');
    }

    /**
     * Dar de baja NO borra la inscripción: cambia su situación. El registro de
     * que el alumno estuvo inscrito forma parte de su historia escolar.
     */
    public function baja(Inscripcion $inscripcion): RedirectResponse
    {
        $inscripcion->update([
            'situacion_id' => SituacionInscripcion::query()->where('clave', 'baja')->value('id'),
        ]);

        return back()->with('exito', 'Inscripción dada de baja.');
    }

    private function matriculaSeleccionada(Request $request): ?MatriculaOferta
    {
        $id = $request->query('matricula_oferta_id');

        return $id === null
            ? null
            : MatriculaOferta::with(['persona', 'oferta.carrera', 'oferta.plan'])->find($id);
    }

    private function cicloSeleccionado(Request $request): ?Ciclo
    {
        $id = $request->query('ciclo_id');

        return $id === null ? null : Ciclo::find($id);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function inscritas(?MatriculaOferta $matricula, ?Ciclo $ciclo): array
    {
        if ($matricula === null || $ciclo === null) {
            return [];
        }

        return Inscripcion::query()
            ->with(['asignaturaGrupo.planMateria.asignatura:id,nombre', 'asignaturaGrupo.grupo:id,clave', 'situacion:id,nombre'])
            ->where('matricula_oferta_id', $matricula->id)
            ->where('ciclo_id', $ciclo->id)
            ->get()
            ->map(fn (Inscripcion $inscripcion) => [
                'id' => $inscripcion->id,
                'materia' => $inscripcion->asignaturaGrupo?->planMateria?->asignatura?->nombre,
                'clave_en_plan' => $inscripcion->asignaturaGrupo?->planMateria?->clave_en_plan,
                'grupo' => $inscripcion->asignaturaGrupo?->grupo?->clave,
                'tipo' => $inscripcion->tipo,
                'situacion' => $inscripcion->situacion?->nombre,
                'calificacion_final' => $inscripcion->calificacion_final,
            ])
            ->all();
    }

    /**
     * Materias abiertas del ciclo con el veredicto de cada una: o se puede
     * inscribir, o se explica exactamente por qué no.
     *
     * @return array<int, array<string, mixed>>
     */
    private function disponibles(?MatriculaOferta $matricula, ?Ciclo $ciclo, ValidadorInscripcion $validador): array
    {
        if ($matricula === null || $ciclo === null) {
            return [];
        }

        return AsignaturaGrupo::query()
            ->with([
                'planMateria.asignatura:id,nombre',
                'grupo:id,clave,ciclo_id,cupo',
                'grupo.ciclo',
                'horarios',
                'docentes.persona',
            ])
            ->whereHas('grupo', fn ($q) => $q->where('ciclo_id', $ciclo->id))
            ->get()
            ->map(function (AsignaturaGrupo $materiaGrupo) use ($matricula, $validador) {
                $impedimentos = $validador->impedimentos($matricula, $materiaGrupo);
                $titular = $materiaGrupo->docentes->firstWhere('pivot.tipo', 'titular');

                return [
                    'id' => $materiaGrupo->id,
                    'materia' => $materiaGrupo->planMateria?->asignatura?->nombre,
                    'clave_en_plan' => $materiaGrupo->planMateria?->clave_en_plan,
                    'periodo' => $materiaGrupo->planMateria?->periodo,
                    'grupo' => $materiaGrupo->grupo?->clave,
                    'titular' => $titular?->persona?->nombreCompleto(),
                    'inscritos' => Inscripcion::query()->where('asignatura_grupo_id', $materiaGrupo->id)->count(),
                    'cupo' => $materiaGrupo->grupo?->cupo,
                    'impedimentos' => $impedimentos,
                    'inscribible' => $impedimentos === [],
                ];
            })
            ->sortBy('periodo')
            ->values()
            ->all();
    }
}
