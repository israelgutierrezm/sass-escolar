<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Academico\Campus;
use App\Models\Academico\Carrera;
use App\Models\Academico\PlanEstudio;
use App\Models\Academico\PlanMateria;
use App\Models\Academico\Turno;
use App\Models\ControlEscolar\AsignaturaGrupo;
use App\Models\ControlEscolar\Ciclo;
use App\Models\ControlEscolar\Docente;
use App\Models\ControlEscolar\Grupo;
use App\Models\ControlEscolar\Inscripcion;
use App\Models\ControlEscolar\SituacionGrupo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Grupos: el contenedor de materias dentro de un ciclo.
 *
 * El grupo no es "un grado escolar": es un conjunto de materias abiertas que
 * comparten ciclo, campus y —normalmente— turno. Un mismo grupo puede alojar
 * materias de planes distintos, que es lo que permite el tronco común.
 */
class GrupoController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('ControlEscolar/Grupos/Index', [
            'grupos' => Grupo::query()
                ->with(['ciclo:id,clave,nombre', 'campus:id,nombre', 'plan:id,nombre', 'turno:id,nombre', 'situacion:id,nombre'])
                ->withCount('asignaturas')
                ->orderByDesc('ciclo_id')
                ->orderBy('clave')
                ->get()
                ->map(fn (Grupo $grupo) => [
                    'id' => $grupo->id,
                    'clave' => $grupo->clave,
                    'nombre' => $grupo->nombre,
                    'ciclo' => $grupo->ciclo?->clave,
                    'campus' => $grupo->campus?->nombre,
                    'plan' => $grupo->plan?->nombre,
                    'turno' => $grupo->turno?->nombre,
                    'situacion' => $grupo->situacion?->nombre,
                    'cupo' => $grupo->cupo,
                    'materias_count' => $grupo->asignaturas_count,
                ]),
            'puedeEditar' => $request->user()->can('abrir-grupos'),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('ControlEscolar/Grupos/Formulario', [
            'grupo' => null,
            ...$this->catalogos(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $grupo = Grupo::create($this->validar($request));

        return redirect()
            ->route('tenant.escolar.grupos.show', $grupo)
            ->with('exito', 'Grupo creado. Ahora abre sus materias.');
    }

    /**
     * Detalle del grupo: sus materias abiertas, con el docente titular de cada
     * una y cuántos alumnos lleva inscritos.
     */
    public function show(Request $request, Grupo $grupo): Response
    {
        $grupo->load(['ciclo:id,clave,nombre', 'campus:id,nombre', 'plan:id,nombre', 'situacion:id,nombre']);

        $asignaturas = AsignaturaGrupo::query()
            ->with(['planMateria.asignatura:id,nombre', 'planMateria.plan:id,nombre', 'situacion:id,nombre', 'docentes.persona'])
            ->where('grupo_id', $grupo->id)
            ->get();

        return Inertia::render('ControlEscolar/Grupos/Detalle', [
            'grupo' => [
                'id' => $grupo->id,
                'clave' => $grupo->clave,
                'nombre' => $grupo->nombre,
                'ciclo' => $grupo->ciclo?->clave,
                'campus' => $grupo->campus?->nombre,
                'plan' => $grupo->plan?->nombre,
                'situacion' => $grupo->situacion?->nombre,
                'cupo' => $grupo->cupo,
            ],
            'asignaturas' => $asignaturas->map(function (AsignaturaGrupo $asignatura) {
                $titular = $asignatura->docentes->firstWhere('pivot.tipo', 'titular');

                return [
                    'id' => $asignatura->id,
                    'clave_en_plan' => $asignatura->planMateria?->clave_en_plan,
                    'materia' => $asignatura->planMateria?->asignatura?->nombre,
                    'plan' => $asignatura->planMateria?->plan?->nombre,
                    'situacion' => $asignatura->situacion?->nombre,
                    'titular' => $titular?->persona?->nombreCompleto(),
                    'adjuntos' => $asignatura->docentes
                        ->where('pivot.tipo', 'adjunto')
                        ->map(fn ($d) => $d->persona?->nombreCompleto())
                        ->values(),
                    // Los ids de quienes ya imparten esta materia, para que el
                    // buscador no vuelva a ofrecerlos.
                    'docentes_asignados' => $asignatura->docentes
                        ->map(fn ($d) => ['id' => $d->persona_id, 'tipo' => $d->pivot->tipo])
                        ->values(),
                    'inscritos' => Inscripcion::query()->where('asignatura_grupo_id', $asignatura->id)->count(),
                ];
            }),
            // Materias del plan que aún no se abren en este grupo.
            'materiasDisponibles' => $this->materiasDisponibles($grupo, $asignaturas->pluck('plan_materia_id')->all()),
            'docentes' => Docente::query()
                ->with('persona:id,nombre,primer_apellido,segundo_apellido')
                ->get()
                ->map(fn (Docente $docente) => [
                    'id' => $docente->persona_id,
                    'nombre' => $docente->persona?->nombreCompleto(),
                ]),
            'puedeEditar' => $request->user()->can('abrir-grupos'),
        ]);
    }

    public function edit(Grupo $grupo): Response
    {
        return Inertia::render('ControlEscolar/Grupos/Formulario', [
            'grupo' => $grupo->only([
                'id', 'ciclo_id', 'campus_id', 'plan_id', 'clave', 'nombre', 'cupo', 'turno_id', 'situacion_id',
            ]),
            ...$this->catalogos(),
        ]);
    }

    public function update(Request $request, Grupo $grupo): RedirectResponse
    {
        $grupo->update($this->validar($request, $grupo->id));

        return redirect()->route('tenant.escolar.grupos.index')->with('exito', 'Grupo actualizado.');
    }

    public function destroy(Grupo $grupo): RedirectResponse
    {
        if ($grupo->asignaturas()->exists()) {
            return back()->with('error', 'No se puede eliminar: el grupo tiene materias abiertas.');
        }

        $grupo->delete();

        return back()->with('exito', 'Grupo eliminado.');
    }

    /**
     * Materias que se pueden abrir aquí: las del plan del grupo (o de cualquier
     * plan, si el grupo no está atado a uno) que todavía no estén abiertas.
     *
     * @param  array<int, int>  $yaAbiertas
     * @return array<int, array<string, mixed>>
     */
    private function materiasDisponibles(Grupo $grupo, array $yaAbiertas): array
    {
        return PlanMateria::query()
            ->with(['asignatura:id,nombre', 'plan:id,nombre'])
            ->when($grupo->plan_id !== null, fn ($q) => $q->where('plan_id', $grupo->plan_id))
            ->whereNotIn('id', $yaAbiertas)
            ->orderBy('periodo')
            ->orderBy('clave_en_plan')
            ->get()
            ->map(fn (PlanMateria $materia) => [
                'id' => $materia->id,
                'clave_en_plan' => $materia->clave_en_plan,
                'materia' => $materia->asignatura?->nombre,
                'plan' => $materia->plan?->nombre,
                // El periodo va suelto, no embebido en la etiqueta: la pantalla
                // filtra por él para proponer "las de tercer semestre" en vez de
                // obligar a leer una lista de cincuenta.
                'periodo' => $materia->periodo,
                'tipo' => $materia->tipo,
                'etiqueta' => sprintf(
                    '%s · %s',
                    $materia->clave_en_plan,
                    $materia->asignatura?->nombre ?? '',
                ),
            ])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function validar(Request $request, ?int $id = null): array
    {
        return $request->validate([
            'ciclo_id' => ['required', 'integer', Rule::exists('ciclos', 'id')->whereNull('deleted_at')],
            'campus_id' => ['required', 'integer', Rule::exists('campus', 'id')->whereNull('deleted_at')],
            'plan_id' => ['nullable', 'integer', Rule::exists('planes_estudio', 'id')->whereNull('deleted_at')],
            'clave' => [
                'required', 'string', 'max:70',
                Rule::unique('grupos', 'clave')
                    ->where('ciclo_id', $request->input('ciclo_id'))
                    ->ignore($id)
                    ->whereNull('deleted_at'),
            ],
            'nombre' => ['nullable', 'string', 'max:200'],
            'cupo' => ['nullable', 'integer', 'min:1'],
            'turno_id' => ['nullable', 'integer', Rule::exists('turnos', 'id')->whereNull('deleted_at')],
            'situacion_id' => ['required', 'integer', Rule::exists('situaciones_grupo', 'id')->whereNull('deleted_at')],
        ], [
            'clave.unique' => 'Ya hay un grupo con esa clave en ese ciclo.',
        ], [
            'ciclo_id' => 'ciclo',
            'campus_id' => 'campus',
            'plan_id' => 'plan de estudios',
            'turno_id' => 'turno',
            'situacion_id' => 'situación',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function catalogos(): array
    {
        return [
            'ciclos' => Ciclo::query()->orderByDesc('fecha_inicio')->get(['id', 'clave', 'nombre'])
                ->map(fn (Ciclo $ciclo) => ['id' => $ciclo->id, 'nombre' => "{$ciclo->clave} — {$ciclo->nombre}"]),
            'campus' => Campus::query()->orderBy('nombre')->get(['id', 'nombre']),
            'carreras' => Carrera::query()->orderBy('nombre')->get(['id', 'nombre']),
            // Los planes viajan con su carrera para que el formulario los
            // filtre en cascada: una escuela con seis carreras y cuatro planes
            // cada una presenta 24 opciones en un solo desplegable, y elegir el
            // plan equivocado ata el grupo a una carrera que no era.
            'planes' => PlanEstudio::query()
                ->orderBy('nombre')
                ->get(['id', 'nombre', 'carrera_id', 'clave'])
                ->map(fn (PlanEstudio $plan) => [
                    'id' => $plan->id,
                    'nombre' => $plan->nombre,
                    'clave' => $plan->clave,
                    'carrera_id' => $plan->carrera_id,
                ]),
            'turnos' => Turno::query()->orderBy('nombre')->get(['id', 'nombre']),
            'situaciones' => SituacionGrupo::query()->orderBy('id')->get(['id', 'nombre']),
        ];
    }
}
