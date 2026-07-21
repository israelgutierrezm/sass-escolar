<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Academico\Area;
use App\Models\Academico\Asignatura;
use App\Models\Academico\ClasificacionAsignatura;
use App\Models\Academico\PlanMateria;
use App\Models\Academico\TipoAsignatura;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Catálogo de asignaturas.
 *
 * Es catálogo PURO: la misma asignatura se reutiliza entre planes y entre
 * carreras. Su vida dentro de un plan concreto —la clave que sale en el acta,
 * el periodo sugerido, si es obligatoria u optativa— no vive aquí sino en
 * `plan_materias`, porque cambia de un plan a otro.
 *
 * Eso es lo que permite el tronco común: una sola "Matemáticas I" en el
 * catálogo, compartida por varias carreras, con distinta clave de acta en cada
 * plan.
 */
class AsignaturaController extends Controller
{
    public function index(Request $request): Response
    {
        $busqueda = trim((string) $request->query('busqueda', ''));

        return Inertia::render('Academico/Asignaturas/Index', [
            'asignaturas' => Asignatura::query()
                ->with(['tipoAsignatura:id,nombre', 'clasificacion:id,nombre', 'area:id,nombre'])
                ->withCount('planMaterias')
                ->when($busqueda !== '', function ($query) use ($busqueda) {
                    $termino = "%{$busqueda}%";

                    $query->where(fn ($q) => $q
                        ->where('nombre', 'like', $termino)
                        ->orWhere('clave', 'like', $termino));
                })
                ->orderBy('nombre')
                ->paginate(20)
                ->withQueryString()
                ->through(fn (Asignatura $asignatura) => [
                    'id' => $asignatura->id,
                    'clave' => $asignatura->clave,
                    'nombre' => $asignatura->nombre,
                    'creditos' => $asignatura->creditos,
                    'tipo' => $asignatura->tipoAsignatura?->nombre,
                    'clasificacion' => $asignatura->clasificacion?->nombre,
                    'area' => $asignatura->area?->nombre,
                    'horas' => $asignatura->horas_teoria + $asignatura->horas_practica,
                    'planes_count' => $asignatura->plan_materias_count,
                ]),
            'filtros' => ['busqueda' => $busqueda],
            'puedeEditar' => $request->user()->can('editar-catalogo-academico'),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Academico/Asignaturas/Formulario', [
            'asignatura' => null,
            ...$this->catalogos(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Asignatura::create($this->validar($request));

        return redirect()->route('tenant.academico.asignaturas.index')->with('exito', 'Asignatura creada.');
    }

    public function edit(Asignatura $asignatura): Response
    {
        return Inertia::render('Academico/Asignaturas/Formulario', [
            'asignatura' => $asignatura->only([
                'id', 'identificador', 'clave', 'nombre', 'creditos', 'tipo_asignatura_id',
                'clasificacion_id', 'area_id', 'horas_teoria', 'horas_practica',
                'horas_acompanamiento', 'horas_independientes', 'objetivos_desc', 'bibliografia_desc',
            ]),
            ...$this->catalogos(),
        ]);
    }

    public function update(Request $request, Asignatura $asignatura): RedirectResponse
    {
        $asignatura->update($this->validar($request, $asignatura->id));

        return redirect()->route('tenant.academico.asignaturas.index')->with('exito', 'Asignatura actualizada.');
    }

    /**
     * Una asignatura usada en algún plan no se elimina: de esas plan_materias
     * cuelgan el historial y las inscripciones.
     */
    public function destroy(Asignatura $asignatura): RedirectResponse
    {
        if (PlanMateria::query()->where('asignatura_id', $asignatura->id)->exists()) {
            return back()->with('error', 'No se puede eliminar: la asignatura está incluida en algún plan.');
        }

        $asignatura->delete();

        return back()->with('exito', 'Asignatura eliminada.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validar(Request $request, ?int $id = null): array
    {
        return $request->validate([
            'identificador' => ['required', 'string', 'max:50'],
            'clave' => ['required', 'string', 'max:50', Rule::unique('asignaturas', 'clave')->ignore($id)->whereNull('deleted_at')],
            'nombre' => ['required', 'string', 'max:255'],
            'creditos' => ['required', 'numeric', 'min:0'],
            'tipo_asignatura_id' => ['required', 'integer', Rule::exists('tipos_asignatura', 'id')->whereNull('deleted_at')],
            'clasificacion_id' => ['nullable', 'integer', Rule::exists('clasificaciones_asignatura', 'id')->whereNull('deleted_at')],
            'area_id' => ['nullable', 'integer', Rule::exists('areas', 'id')->whereNull('deleted_at')],
            'horas_teoria' => ['nullable', 'integer', 'min:0'],
            'horas_practica' => ['nullable', 'integer', 'min:0'],
            'horas_acompanamiento' => ['nullable', 'integer', 'min:0'],
            'horas_independientes' => ['nullable', 'integer', 'min:0'],
            'objetivos_desc' => ['nullable', 'string'],
            'bibliografia_desc' => ['nullable', 'string'],
        ], [], [
            'tipo_asignatura_id' => 'tipo de asignatura',
            'clasificacion_id' => 'clasificación',
            'area_id' => 'área',
            'horas_teoria' => 'horas de teoría',
            'horas_practica' => 'horas de práctica',
            'horas_acompanamiento' => 'horas de acompañamiento',
            'horas_independientes' => 'horas independientes',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function catalogos(): array
    {
        return [
            'tiposAsignatura' => TipoAsignatura::query()->orderBy('nombre')->get(['id', 'nombre']),
            'clasificaciones' => ClasificacionAsignatura::query()->orderBy('nombre')->get(['id', 'nombre']),
            'areas' => Area::query()->orderBy('nombre')->get(['id', 'nombre']),
        ];
    }
}
