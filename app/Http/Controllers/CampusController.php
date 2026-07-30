<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Academico\Campus;
use App\Models\Academico\Institucion;
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
        $filtros = [
            'busqueda' => trim((string) $request->query('busqueda', '')),
            'institucion_id' => $request->query('institucion_id'),
            'tipo_campus_id' => $request->query('tipo_campus_id'),
        ];

        return Inertia::render('Academico/Campus/Index', [
            'campus' => Campus::query()
                ->with(['tipoCampus:id,nombre', 'entidad:id,nombre', 'institucion:id,nombre'])
                ->withCount('ofertas')
                ->when($filtros['busqueda'] !== '', fn ($q) => $q->where(fn ($sub) => $sub
                    ->where('clave', 'like', "%{$filtros['busqueda']}%")
                    ->orWhere('nombre', 'like', "%{$filtros['busqueda']}%")))
                ->when($filtros['institucion_id'], fn ($q, $v) => $q->where('institucion_id', $v))
                ->when($filtros['tipo_campus_id'], fn ($q, $v) => $q->where('tipo_campus_id', $v))
                ->orderBy('nombre')
                ->paginate(10)
                ->withQueryString()
                ->through(fn (Campus $campus) => [
                    'id' => $campus->id,
                    'clave' => $campus->clave,
                    'nombre' => $campus->nombre,
                    'institucion' => $campus->institucion?->nombre,
                    'tipo' => $campus->tipoCampus?->nombre,
                    'entidad' => $campus->entidad?->nombre,
                    'online' => $campus->online,
                    'ofertas_count' => $campus->ofertas_count,
                ]),
            'filtros' => $filtros,
            'instituciones' => Institucion::query()->orderBy('nombre')->get(['id', 'nombre']),
            'tiposCampus' => TipoCampus::query()->orderBy('nombre')->get(['id', 'nombre']),
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
            'campus' => $campus->only(['id', 'clave', 'identificador', 'nombre', 'institucion_id', 'tipo_campus_id', 'online', 'entidad_id']),
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
        $datos = $request->validate([
            'clave' => ['required', 'string', 'max:50', Rule::unique('campus', 'clave')->ignore($id)->whereNull('deleted_at')],
            'identificador' => ['nullable', 'string', 'max:40'],
            'nombre' => ['required', 'string', 'max:255'],
            // Informativo, no condiciona nada; por eso nullable. La UI lo
            // preselecciona cuando solo hay una institución.
            'institucion_id' => ['nullable', 'integer', Rule::exists('instituciones', 'id')->whereNull('deleted_at')],
            // Opcional: no toda escuela clasifica sus planteles por tipo.
            'tipo_campus_id' => ['nullable', 'integer', Rule::exists('tipos_campus', 'id')->whereNull('deleted_at')],
            // La entidad federativa (LUGARES) pasa a OBLIGATORIA: un plantel
            // siempre está en algún lado —aunque ese lado sea «Extranjero»—.
            // Sin `exists`: el catálogo vive en la landlord (otra conexión) y se
            // referencia sin FK, igual que el resto de refs a datos centrales.
            'entidad_id' => ['required', 'integer'],
        ], [], [
            'institucion_id' => 'institución',
            'tipo_campus_id' => 'tipo de campus',
            'entidad_id' => 'entidad federativa',
        ]);

        // «En línea» ya no es un checkbox aparte —era doble confirmación—: se
        // deriva del tipo de campus (la clave `en_linea` del catálogo). Sin tipo
        // no hay nada que derivar.
        $datos['online'] = filled($datos['tipo_campus_id'] ?? null)
            && TipoCampus::query()->whereKey($datos['tipo_campus_id'])->value('clave') === 'en_linea';

        return $datos;
    }

    /**
     * @return array<string, mixed>
     */
    private function catalogos(): array
    {
        return [
            'tiposCampus' => TipoCampus::query()->orderBy('nombre')->get(['id', 'nombre']),
            // Entidad de LUGARES (el 33 es «Extranjero», no «Nacido en…»).
            'entidades' => EntidadFederativa::query()->orderBy('nombre')->get(['id', 'nombre']),
            'instituciones' => Institucion::query()->orderBy('nombre')->get(['id', 'nombre']),
        ];
    }
}
