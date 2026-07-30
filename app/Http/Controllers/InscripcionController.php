<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Admisiones\MatriculaOferta;
use App\Models\ControlEscolar\AsignaturaGrupo;
use App\Models\ControlEscolar\Ciclo;
use App\Models\ControlEscolar\Grupo;
use App\Models\ControlEscolar\Inscripcion;
use App\Models\ControlEscolar\SituacionInscripcion;
use App\Models\ControlEscolar\TipoEvaluacion;
use App\Services\CiclosCongruentes;
use App\Services\ValidadorInscripcion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
            // Los ciclos se acotan al alumno elegido (su campus y nivel), igual
            // que en el kárdex. Sin alumno todavía, se muestran todos.
            'ciclos' => ($matricula === null
                ? Ciclo::query()->orderByDesc('fecha_inicio')->get(['id', 'clave', 'nombre'])
                : app(CiclosCongruentes::class)->paraAlumno($matricula))
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
            // Con qué tipo de evaluación se inscribe (ordinaria, extraordinaria,
            // a título, recursamiento, revalidación, regularización).
            'tiposEvaluacion' => TipoEvaluacion::query()->orderBy('id')->get(['id', 'nombre']),
            'puedeInscribir' => $request->user()->can('inscribir-alumnos'),
        ]);
    }

    public function store(Request $request, ValidadorInscripcion $validador): RedirectResponse
    {
        $datos = $request->validate([
            'matricula_oferta_id' => ['required', 'integer', Rule::exists('matricula_oferta', 'id')->whereNull('deleted_at')],
            'asignatura_grupo_id' => ['required', 'integer', Rule::exists('asignatura_grupo', 'id')->whereNull('deleted_at')],
            'tipo_evaluacion_id' => ['required', 'integer', Rule::exists('tipos_evaluacion', 'id')],
        ], [], [
            'matricula_oferta_id' => 'alumno',
            'asignatura_grupo_id' => 'materia',
            'tipo_evaluacion_id' => 'tipo de evaluación',
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

        // El `tipo` (ordinaria/recursamiento) —que consultan el validador y el
        // asentador— se deriva del tipo de evaluación elegido.
        $claveEval = TipoEvaluacion::query()->whereKey($datos['tipo_evaluacion_id'])->value('clave');
        $tipo = $claveEval === Inscripcion::TIPO_RECURSAMIENTO
            ? Inscripcion::TIPO_RECURSAMIENTO
            : Inscripcion::TIPO_ORDINARIA;

        Inscripcion::create([
            'matricula_oferta_id' => $matricula->id,
            'asignatura_grupo_id' => $materiaGrupo->id,
            'ciclo_id' => $materiaGrupo->grupo->ciclo_id,
            'tipo' => $tipo,
            'tipo_evaluacion_id' => $datos['tipo_evaluacion_id'],
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

    /**
     * Inscripción masiva a un grupo: se ven los grupos del ciclo y, por grupo,
     * se sugiere a los alumnos de su plan que van en el grado del grupo, están
     * activos y aún no tienen inscripción en ese ciclo. Un buscador permite
     * añadir a cualquier otro alumno activo del plan.
     */
    public function masiva(Request $request): Response
    {
        $ciclo = $this->cicloSeleccionado($request);

        $grupo = $request->query('grupo_id') === null
            ? null
            : Grupo::query()
                ->with(['plan:id,nombre', 'ciclo:id,clave', 'asignaturas.planMateria.asignatura:id,nombre'])
                ->find($request->query('grupo_id'));

        return Inertia::render('ControlEscolar/Inscripciones/Masiva', [
            'ciclos' => Ciclo::query()->orderByDesc('fecha_inicio')->get(['id', 'clave', 'nombre'])
                ->map(fn (Ciclo $c) => ['id' => $c->id, 'etiqueta' => "{$c->clave} — {$c->nombre}"]),
            'grupos' => $ciclo === null ? [] : Grupo::query()->with('plan:id,nombre')
                ->where('ciclo_id', $ciclo->id)->orderBy('clave')->get()
                ->map(fn (Grupo $g) => ['id' => $g->id, 'etiqueta' => trim($g->clave.' · '.($g->plan?->nombre ?? 'sin plan'))]),
            'seleccion' => ['ciclo_id' => $ciclo?->id, 'grupo_id' => $grupo?->id],
            'grupo' => $grupo === null ? null : $this->datosGrupoMasiva($grupo),
            'candidatos' => $grupo === null ? [] : $this->candidatosMasiva($grupo),
            'puedeInscribir' => $request->user()->can('inscribir-alumnos'),
        ]);
    }

    /**
     * Inscribe a los alumnos seleccionados en TODAS las materias del grupo. Cada
     * materia se valida por separado: la que no pase (seriación, cupo, ya
     * inscrito) se omite, no truena la carga completa.
     */
    public function inscribirMasiva(Request $request, ValidadorInscripcion $validador): RedirectResponse
    {
        $datos = $request->validate([
            'grupo_id' => ['required', 'integer', Rule::exists('grupos', 'id')->whereNull('deleted_at')],
            'matricula_oferta_ids' => ['required', 'array', 'min:1'],
            'matricula_oferta_ids.*' => ['integer', Rule::exists('matricula_oferta', 'id')->whereNull('deleted_at')],
        ], [], ['matricula_oferta_ids' => 'alumnos']);

        $grupo = Grupo::with('asignaturas')->findOrFail($datos['grupo_id']);

        if ($grupo->asignaturas->isEmpty()) {
            return back()->with('error', 'El grupo no tiene materias abiertas.');
        }

        $ordinaria = TipoEvaluacion::query()->where('clave', Inscripcion::TIPO_ORDINARIA)->value('id');
        $inscritoId = SituacionInscripcion::query()->where('clave', 'inscrito')->value('id');

        $renglones = 0;
        $omitidos = 0;

        DB::transaction(function () use ($datos, $grupo, $validador, $ordinaria, $inscritoId, &$renglones, &$omitidos) {
            foreach ($datos['matricula_oferta_ids'] as $matriculaId) {
                $matricula = MatriculaOferta::find($matriculaId);

                if ($matricula === null) {
                    continue;
                }

                foreach ($grupo->asignaturas as $materiaGrupo) {
                    if ($validador->impedimentos($matricula, $materiaGrupo) !== []) {
                        $omitidos++;

                        continue;
                    }

                    Inscripcion::create([
                        'matricula_oferta_id' => $matricula->id,
                        'asignatura_grupo_id' => $materiaGrupo->id,
                        'ciclo_id' => $grupo->ciclo_id,
                        'tipo' => Inscripcion::TIPO_ORDINARIA,
                        'tipo_evaluacion_id' => $ordinaria,
                        'forma_inscripcion' => Inscripcion::FORMA_ADMINISTRATIVA,
                        'situacion_id' => $inscritoId,
                    ]);
                    $renglones++;
                }
            }
        });

        $mensaje = "{$renglones} renglón(es) inscritos".
            ($omitidos > 0 ? ", {$omitidos} omitidos por validación." : '.');

        return back()->with($renglones > 0 ? 'exito' : 'error', $mensaje);
    }

    /**
     * @return array<string, mixed>
     */
    private function datosGrupoMasiva(Grupo $grupo): array
    {
        return [
            'id' => $grupo->id,
            'clave' => $grupo->clave,
            'plan' => $grupo->plan?->nombre,
            'ciclo' => $grupo->ciclo?->clave,
            'periodo_objetivo' => $this->periodoObjetivo($grupo),
            'materias' => $grupo->asignaturas->map(fn (AsignaturaGrupo $ag) => [
                'clave_en_plan' => $ag->planMateria?->clave_en_plan,
                'nombre' => $ag->planMateria?->asignatura?->nombre,
                'periodo' => $ag->planMateria?->periodo,
            ])->values()->all(),
        ];
    }

    /**
     * Alumnos activos del plan del grupo sin inscripción en el ciclo.
     * `sugerido` = va en el grado del grupo (periodo_actual = periodo objetivo).
     *
     * @return array<int, array<string, mixed>>
     */
    private function candidatosMasiva(Grupo $grupo): array
    {
        $objetivo = $this->periodoObjetivo($grupo);

        $yaEnCiclo = Inscripcion::query()->where('ciclo_id', $grupo->ciclo_id)->distinct()->pluck('matricula_oferta_id');

        return MatriculaOferta::query()
            ->with(['persona', 'oferta.carrera:id,nombre'])
            ->where('estatus', 'activo')
            ->whereHas('oferta', fn ($q) => $q->where('plan_id', $grupo->plan_id))
            ->whereNotIn('id', $yaEnCiclo)
            ->orderBy('matricula')
            ->get()
            ->map(fn (MatriculaOferta $m) => [
                'id' => $m->id,
                'matricula' => $m->matricula,
                'nombre' => $m->persona?->nombreCompleto(),
                'carrera' => $m->oferta?->carrera?->nombre,
                'periodo_actual' => $m->periodo_actual,
                'foto' => $m->persona?->urlFoto(),
                'sugerido' => $objetivo !== null && $m->periodo_actual === $objetivo,
            ])
            ->all();
    }

    /** Periodo (grado) del grupo: el más común entre sus materias. */
    private function periodoObjetivo(Grupo $grupo): ?int
    {
        $periodos = $grupo->asignaturas->map(fn (AsignaturaGrupo $ag) => $ag->planMateria?->periodo)->filter()->values();

        return $periodos->isEmpty() ? null : (int) $periodos->mode()[0];
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
            ->with(['asignaturaGrupo.planMateria.asignatura:id,nombre', 'asignaturaGrupo.grupo:id,clave', 'situacion:id,nombre', 'tipoEvaluacion:id,nombre'])
            ->where('matricula_oferta_id', $matricula->id)
            ->where('ciclo_id', $ciclo->id)
            ->get()
            ->map(fn (Inscripcion $inscripcion) => [
                'id' => $inscripcion->id,
                'materia' => $inscripcion->asignaturaGrupo?->planMateria?->asignatura?->nombre,
                'clave_en_plan' => $inscripcion->asignaturaGrupo?->planMateria?->clave_en_plan,
                'grupo' => $inscripcion->asignaturaGrupo?->grupo?->clave,
                'tipo' => $inscripcion->tipo,
                'tipo_evaluacion' => $inscripcion->tipoEvaluacion?->nombre,
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
