<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Academico\Asignatura;
use App\Models\Academico\EsquemaEvaluacion;
use App\Models\Academico\PlanEstudio;
use App\Models\Academico\PlanMateria;
use App\Models\ControlEscolar\AsignaturaGrupo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Malla curricular de un plan: qué asignaturas lo componen y cómo.
 *
 * `plan_materias` es el núcleo del modelo curricular. La asignatura viene del
 * catálogo, pero aquí se define lo que cambia entre planes: la clave que sale
 * en el acta, el periodo sugerido, si es obligatoria u optativa y —si difiere
 * del catálogo— sus créditos.
 *
 * Todo lo demás del sistema cuelga de aquí: los grupos abren una plan_materia,
 * el kárdex registra plan_materias y la seriación se define entre ellas.
 */
class PlanMateriaController extends Controller
{
    public function index(Request $request, PlanEstudio $plan): Response
    {
        $plan->load('carrera:id,nombre');

        $materias = PlanMateria::query()
            ->with(['asignatura:id,clave,nombre,creditos,tipo_asignatura_id', 'asignatura.tipoAsignatura:id,nombre'])
            ->where('plan_id', $plan->id)
            ->orderBy('periodo')
            ->orderBy('clave_en_plan')
            ->get();

        // Los créditos efectivos son los del plan si se sobreescribieron, o los
        // del catálogo si no.
        $creditosCargados = $materias->sum(
            fn (PlanMateria $materia) => $materia->creditos_en_plan ?? $materia->asignatura?->creditos ?? 0
        );

        return Inertia::render('Academico/Planes/Materias', [
            'plan' => [
                'id' => $plan->id,
                'nombre' => $plan->nombre,
                'clave' => $plan->clave,
                'carrera' => $plan->carrera?->nombre,
                'total_periodos' => $plan->total_periodos,
                'total_creditos' => $plan->total_creditos,
                'minimo_creditos' => $plan->minimo_creditos,
            ],
            'materias' => $materias->map(fn (PlanMateria $materia) => [
                'id' => $materia->id,
                'asignatura_id' => $materia->asignatura_id,
                'asignatura' => $materia->asignatura?->nombre,
                'asignatura_clave' => $materia->asignatura?->clave,
                'clave_en_plan' => $materia->clave_en_plan,
                'periodo' => $materia->periodo,
                'tipo' => $materia->tipo,
                'creditos' => $materia->creditos_en_plan ?? $materia->asignatura?->creditos,
                'creditos_sobreescritos' => $materia->creditos_en_plan !== null,
            ]),
            'creditosCargados' => $creditosCargados,
            'asignaturas' => Asignatura::query()
                ->orderBy('nombre')
                ->get(['id', 'clave', 'nombre', 'creditos']),
            'puedeEditar' => $request->user()->can('editar-catalogo-academico'),
        ]);
    }

    /**
     * Detalle de una materia dentro del plan: sus prerrequisitos y la
     * composición de su calificación.
     */
    public function show(Request $request, PlanEstudio $plan, PlanMateria $materia): Response
    {
        abort_unless($materia->plan_id === $plan->id, 404);

        $materia->load([
            'asignatura:id,clave,nombre,creditos',
            'seriacion.requiere.asignatura:id,nombre',
            'esquemaEvaluacion',
            'plantillaEvaluacion:id,nombre',
        ]);

        $plan->load('carrera:id,nombre');

        $componentes = $materia->esquemaEvaluacion->sortBy('orden')->values();

        return Inertia::render('Academico/Planes/DetalleMateria', [
            'plan' => ['id' => $plan->id, 'nombre' => $plan->nombre, 'carrera' => $plan->carrera?->nombre],
            'materia' => [
                'id' => $materia->id,
                'clave_en_plan' => $materia->clave_en_plan,
                'asignatura' => $materia->asignatura?->nombre,
                'periodo' => $materia->periodo,
                'tipo' => $materia->tipo,
                'creditos' => $materia->creditos_en_plan ?? $materia->asignatura?->creditos,
            ],
            'seriacion' => $materia->seriacion->map(fn ($requisito) => [
                'id' => $requisito->id,
                'tipo' => $requisito->tipo,
                'minimo_creditos' => $requisito->minimo_creditos,
                'requiere' => $requisito->requiere === null ? null : [
                    'clave_en_plan' => $requisito->requiere->clave_en_plan,
                    'nombre' => $requisito->requiere->asignatura?->nombre,
                ],
            ]),
            'componentes' => $componentes->map(fn (EsquemaEvaluacion $componente) => [
                'id' => $componente->id,
                'componente' => $componente->componente,
                'parcial' => $componente->parcial,
                'porcentaje' => (float) $componente->porcentaje,
                'orden' => $componente->orden,
            ]),
            'sumaPorcentajes' => (float) $componentes->sum('porcentaje'),
            // De dónde salió el esquema. NULL significa que se armó a mano y
            // que ninguna re-propagación de plantilla lo va a tocar.
            'plantilla' => $materia->plantillaEvaluacion === null ? null : [
                'id' => $materia->plantillaEvaluacion->id,
                'nombre' => $materia->plantillaEvaluacion->nombre,
            ],
            // Candidatas a requisito: el resto de materias del mismo plan.
            'candidatas' => PlanMateria::query()
                ->with('asignatura:id,nombre')
                ->where('plan_id', $plan->id)
                ->whereKeyNot($materia->id)
                ->orderBy('periodo')
                ->get()
                ->map(fn (PlanMateria $otra) => [
                    'id' => $otra->id,
                    'etiqueta' => sprintf(
                        '%s · %s%s',
                        $otra->clave_en_plan,
                        $otra->asignatura?->nombre ?? '',
                        $otra->periodo !== null ? " (periodo {$otra->periodo})" : '',
                    ),
                ]),
            'puedeEditar' => $request->user()->can('editar-catalogo-academico'),
        ]);
    }

    public function store(Request $request, PlanEstudio $plan): RedirectResponse
    {
        PlanMateria::create([
            ...$this->validar($request, $plan),
            'plan_id' => $plan->id,
        ]);

        return back()->with('exito', 'Materia agregada al plan.');
    }

    public function update(Request $request, PlanEstudio $plan, PlanMateria $materia): RedirectResponse
    {
        abort_unless($materia->plan_id === $plan->id, 404);

        $materia->update($this->validar($request, $plan, $materia->id));

        return back()->with('exito', 'Materia actualizada.');
    }

    /**
     * Una materia ya abierta en un grupo no se quita del plan: hay alumnos
     * inscritos y calificaciones asentadas contra ella.
     */
    public function destroy(PlanEstudio $plan, PlanMateria $materia): RedirectResponse
    {
        abort_unless($materia->plan_id === $plan->id, 404);

        if (AsignaturaGrupo::query()->where('plan_materia_id', $materia->id)->exists()) {
            return back()->with('error', 'No se puede quitar: la materia ya se abrió en algún grupo.');
        }

        $materia->delete();

        return back()->with('exito', 'Materia retirada del plan.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validar(Request $request, PlanEstudio $plan, ?int $id = null): array
    {
        return $request->validate([
            'asignatura_id' => ['required', 'integer', Rule::exists('asignaturas', 'id')->whereNull('deleted_at')],
            // La clave de acta es única DENTRO del plan, no globalmente: dos
            // planes distintos pueden usar la misma clave para su materia.
            'clave_en_plan' => [
                'required', 'string', 'max:50',
                Rule::unique('plan_materias', 'clave_en_plan')
                    ->where('plan_id', $plan->id)
                    ->ignore($id)
                    ->whereNull('deleted_at'),
            ],
            'periodo' => ['nullable', 'integer', 'min:1', 'max:30'],
            'tipo' => ['required', Rule::in(['obligatoria', 'optativa', 'tronco_comun'])],
            'creditos_en_plan' => ['nullable', 'numeric', 'min:0'],
        ], [
            'clave_en_plan.unique' => 'Ya hay una materia con esa clave en este plan.',
        ], [
            'asignatura_id' => 'asignatura',
            'clave_en_plan' => 'clave en el plan',
            'creditos_en_plan' => 'créditos',
        ]);
    }
}
