<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Academico\AutorizacionReconocimiento;
use App\Models\Academico\Carrera;
use App\Models\Academico\PlanEstudio;
use App\Models\Academico\TipoPeriodo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Planes de estudio. Un plan pertenece a una carrera y define las reglas
 * académicas (escala de calificación, créditos para titularse) y los datos que
 * la SEP exige para el título electrónico (RVOE y tipo de autorización).
 *
 * Una carrera puede tener varios planes coexistiendo: `vigente` distingue el
 * que se ofrece hoy de los que solo siguen vivos para los alumnos que los
 * cursan.
 */
class PlanEstudioController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('Academico/Planes/Index', [
            'planes' => PlanEstudio::query()
                ->with(['carrera:id,nombre', 'tipoPeriodo:id,nombre'])
                ->withCount('planMaterias')
                ->orderBy('carrera_id')
                ->orderByDesc('vigente')
                ->get()
                ->map(fn (PlanEstudio $plan) => [
                    'id' => $plan->id,
                    'clave' => $plan->clave,
                    'nombre' => $plan->nombre,
                    'carrera' => $plan->carrera?->nombre,
                    'periodo' => $plan->tipoPeriodo?->nombre,
                    'rvoe' => $plan->rvoe,
                    'vigente' => $plan->vigente,
                    'total_creditos' => $plan->total_creditos,
                    'materias_count' => $plan->plan_materias_count,
                ]),
            'puedeEditar' => $request->user()->can('editar-catalogo-academico'),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Academico/Planes/Formulario', [
            'plan' => null,
            ...$this->catalogos(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        PlanEstudio::create($this->validar($request));

        return redirect()->route('tenant.academico.planes.index')->with('exito', 'Plan de estudios creado.');
    }

    public function edit(PlanEstudio $plane): Response
    {
        return Inertia::render('Academico/Planes/Formulario', [
            'plan' => [
                ...$plane->only([
                    'id', 'carrera_id', 'clave', 'abreviacion', 'nombre', 'rvoe',
                    'autorizacion_reconocimiento_id', 'tipo_periodo_id', 'total_periodos',
                    'calificacion_minima', 'calificacion_maxima', 'calificacion_minima_aprobatoria',
                    'minimo_creditos', 'minimo_asignaturas', 'total_creditos',
                    'curp_responsable', 'vigente',
                ]),
                'fecha_rvoe' => $plane->fecha_rvoe?->toDateString(),
            ],
            ...$this->catalogos(),
        ]);
    }

    public function update(Request $request, PlanEstudio $plane): RedirectResponse
    {
        $plane->update($this->validar($request, $plane->id));

        return redirect()->route('tenant.academico.planes.index')->with('exito', 'Plan actualizado.');
    }

    /**
     * Un plan con materias no se elimina: de sus plan_materias cuelgan el
     * historial y las inscripciones de los alumnos.
     */
    public function destroy(PlanEstudio $plane): RedirectResponse
    {
        if ($plane->planMaterias()->exists()) {
            return back()->with('error', 'No se puede eliminar: el plan tiene materias registradas.');
        }

        $plane->delete();

        return back()->with('exito', 'Plan eliminado.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validar(Request $request, ?int $id = null): array
    {
        return $request->validate([
            'carrera_id' => ['required', 'integer', Rule::exists('carreras', 'id')->whereNull('deleted_at')],
            'clave' => ['required', 'string', 'max:50', Rule::unique('planes_estudio', 'clave')->ignore($id)->whereNull('deleted_at')],
            'abreviacion' => ['nullable', 'string', 'max:50'],
            'nombre' => ['required', 'string', 'max:255'],
            'rvoe' => ['required', 'string', 'max:100'],
            'fecha_rvoe' => ['nullable', 'date'],
            'autorizacion_reconocimiento_id' => ['required', 'integer', Rule::exists('autorizaciones_reconocimiento', 'id')->whereNull('deleted_at')],
            'tipo_periodo_id' => ['required', 'integer', Rule::exists('tipos_periodo', 'id')->whereNull('deleted_at')],
            'total_periodos' => ['nullable', 'integer', 'min:1', 'max:30'],
            'calificacion_minima' => ['required', 'integer', 'min:0'],
            'calificacion_maxima' => ['required', 'integer', 'gt:calificacion_minima'],
            'calificacion_minima_aprobatoria' => ['required', 'integer', 'gte:calificacion_minima', 'lte:calificacion_maxima'],
            'minimo_creditos' => ['required', 'numeric', 'min:0'],
            'minimo_asignaturas' => ['nullable', 'integer', 'min:0'],
            'total_creditos' => ['required', 'numeric', 'gte:minimo_creditos'],
            'curp_responsable' => ['nullable', 'string', 'size:18'],
            'vigente' => ['boolean'],
        ], [
            'calificacion_maxima.gt' => 'La calificación máxima debe ser mayor que la mínima.',
            'calificacion_minima_aprobatoria.gte' => 'La mínima aprobatoria no puede ser menor que la calificación mínima.',
            'calificacion_minima_aprobatoria.lte' => 'La mínima aprobatoria no puede superar la calificación máxima.',
            'total_creditos.gte' => 'El total de créditos no puede ser menor que el mínimo para titularse.',
        ], [
            'carrera_id' => 'carrera',
            'autorizacion_reconocimiento_id' => 'tipo de autorización',
            'tipo_periodo_id' => 'tipo de periodo',
            'curp_responsable' => 'CURP del responsable',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function catalogos(): array
    {
        return [
            'carreras' => Carrera::query()->orderBy('nombre')->get(['id', 'nombre']),
            'autorizaciones' => AutorizacionReconocimiento::query()->orderBy('nombre')->get(['id', 'nombre']),
            'tiposPeriodo' => TipoPeriodo::query()->orderBy('nombre')->get(['id', 'nombre']),
        ];
    }
}
