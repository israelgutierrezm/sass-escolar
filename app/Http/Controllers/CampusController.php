<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Academico\Campus;
use App\Models\Academico\TipoCampus;
use App\Models\Landlord\EntidadFederativa;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Planteles de la escuela. Es la base de la estructura académica: la oferta y
 * los grupos cuelgan de un campus.
 */
class CampusController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('Academico/Campus/Index', [
            'campus' => Campus::query()
                ->with(['tipoCampus:id,nombre', 'entidad:id,nombre'])
                ->withCount('ofertas')
                ->orderBy('nombre')
                ->get()
                ->map(fn (Campus $campus) => [
                    'id' => $campus->id,
                    'clave' => $campus->clave,
                    'nombre' => $campus->nombre,
                    'tipo' => $campus->tipoCampus?->nombre,
                    'entidad' => $campus->entidad?->nombre,
                    'online' => $campus->online,
                    'ofertas_count' => $campus->ofertas_count,
                ]),
            'puedeEditar' => $request->user()->can('editar-catalogo-academico'),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Academico/Campus/Formulario', [
            'campus' => null,
            ...$this->catalogos(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Campus::create($this->validar($request));

        return redirect()->route('tenant.academico.campus.index')->with('exito', 'Campus creado.');
    }

    public function edit(Campus $campus): Response
    {
        return Inertia::render('Academico/Campus/Formulario', [
            'campus' => $campus->only(['id', 'clave', 'nombre', 'tipo_campus_id', 'online', 'entidad_id']),
            ...$this->catalogos(),
        ]);
    }

    public function update(Request $request, Campus $campus): RedirectResponse
    {
        $campus->update($this->validar($request, $campus->id));

        return redirect()->route('tenant.academico.campus.index')->with('exito', 'Campus actualizado.');
    }

    /**
     * No se elimina un campus con oferta activa: se perdería la trazabilidad de
     * dónde se imparte cada plan. Primero hay que mover o cerrar esa oferta.
     */
    public function destroy(Campus $campus): RedirectResponse
    {
        if ($campus->ofertas()->exists()) {
            return back()->with('error', 'No se puede eliminar: el campus tiene oferta registrada.');
        }

        $campus->delete();

        return back()->with('exito', 'Campus eliminado.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validar(Request $request, ?int $id = null): array
    {
        return $request->validate([
            'clave' => ['required', 'string', 'max:50', Rule::unique('campus', 'clave')->ignore($id)->whereNull('deleted_at')],
            'nombre' => ['required', 'string', 'max:255'],
            'tipo_campus_id' => ['required', 'integer', Rule::exists('tipos_campus', 'id')->whereNull('deleted_at')],
            'online' => ['boolean'],
            'entidad_id' => ['nullable', 'integer'],
        ], [], [
            'tipo_campus_id' => 'tipo de campus',
            'entidad_id' => 'entidad federativa',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function catalogos(): array
    {
        return [
            'tiposCampus' => TipoCampus::query()->orderBy('nombre')->get(['id', 'nombre']),
            'entidades' => EntidadFederativa::query()->orderBy('nombre')->get(['id', 'nombre']),
        ];
    }
}
