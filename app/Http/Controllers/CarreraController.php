<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Academico\Carrera;
use App\Models\Academico\NivelEstudio;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Carreras. Incluye los campos que exige la SEP (clave SAT para CFDI) y la
 * lista de documentos que la carrera pide en admisión.
 */
class CarreraController extends Controller
{
    public function index(Request $request): Response
    {
        $filtros = [
            'busqueda' => trim((string) $request->query('busqueda', '')),
            'nivel_estudios_id' => $request->query('nivel_estudios_id'),
        ];

        return Inertia::render('Academico/Carreras/Index', [
            'carreras' => Carrera::query()
                ->with('nivelEstudios:id,nombre')
                ->withCount('planes')
                ->when($filtros['busqueda'] !== '', fn ($q) => $q->where(fn ($sub) => $sub
                    ->where('clave', 'like', "%{$filtros['busqueda']}%")
                    ->orWhere('nombre', 'like', "%{$filtros['busqueda']}%")
                    ->orWhere('identificador', 'like', "%{$filtros['busqueda']}%")))
                ->when($filtros['nivel_estudios_id'], fn ($q, $v) => $q->where('nivel_estudios_id', $v))
                ->orderBy('nombre')
                ->paginate(10)
                ->withQueryString()
                ->through(fn (Carrera $carrera) => [
                    'id' => $carrera->id,
                    'clave' => $carrera->clave,
                    'nombre' => $carrera->nombre,
                    'nivel' => $carrera->nivelEstudios?->nombre,
                    'planes_count' => $carrera->planes_count,
                ]),
            'filtros' => $filtros,
            'niveles' => NivelEstudio::query()->orderBy('orden')->get(['id', 'nombre']),
            'puedeEditar' => $request->user()->can('editar-catalogo-academico'),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Academico/Carreras/Formulario', [
            'carrera' => null,
            ...$this->catalogos(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Carrera::create($this->validar($request));

        return redirect()->route('tenant.academico.carreras.index')->with('exito', 'Carrera creada.');
    }

    public function edit(Carrera $carrera): Response
    {
        return Inertia::render('Academico/Carreras/Formulario', [
            'carrera' => $carrera->only([
                'id', 'identificador', 'clave', 'nombre', 'nivel_estudios_id',
                'imagen_url', 'emite_documentos_oficiales',
            ]),
            ...$this->catalogos(),
        ]);
    }

    public function update(Request $request, Carrera $carrera): RedirectResponse
    {
        $carrera->update($this->validar($request, $carrera->id));

        return redirect()->route('tenant.academico.carreras.index')->with('exito', 'Carrera actualizada.');
    }

    /**
     * Una carrera con planes no se elimina: sus planes cuelgan de ella y a su
     * vez tienen materias e historial.
     */
    public function destroy(Carrera $carrera): RedirectResponse
    {
        if ($carrera->planes()->exists()) {
            return back()->with('error', 'No se puede eliminar: la carrera tiene planes de estudio.');
        }

        $carrera->delete();

        return back()->with('exito', 'Carrera eliminada.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validar(Request $request, ?int $id = null): array
    {
        return $request->validate([
            'identificador' => ['required', 'string', 'max:50'],
            'clave' => ['required', 'string', 'max:50', Rule::unique('carreras', 'clave')->ignore($id)->whereNull('deleted_at')],
            'nombre' => ['required', 'string', 'max:255'],
            // Ya es catálogo TENANT (misma conexión), así que se puede validar
            // que exista de verdad, cosa que con la landlord no se hacía.
            'nivel_estudios_id' => ['required', 'integer', Rule::exists('niveles_estudio', 'id')->whereNull('deleted_at')],
            // «Objetivo» se retiró del formulario a pedido del cliente. La
            // columna se conserva por si vuelve, pero ya no se captura aquí.
            'imagen_url' => ['nullable', 'string', 'max:255'],
            /*
             * Si expide documentos oficiales.
             *
             * `boolean` y no `required`: una casilla desmarcada no viaja en el
             * formulario, y exigirla haría imposible apagarla.
             */
            'emite_documentos_oficiales' => ['boolean'],
        ], [], [
            'nivel_estudios_id' => 'nivel de estudios',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function catalogos(): array
    {
        return [
            // La clave SAT ya no se captura por carrera: vive en el nivel de
            // estudios (el SAT la asigna por nivel).
            'niveles' => NivelEstudio::query()->orderBy('orden')->get(['id', 'nombre']),
        ];
    }
}
