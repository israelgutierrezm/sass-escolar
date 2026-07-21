<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Academico\Carrera;
use App\Models\Admisiones\DocumentoRequerido;
use App\Models\Landlord\NivelEstudio;
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
        return Inertia::render('Academico/Carreras/Index', [
            'carreras' => Carrera::query()
                ->with('nivelEstudios:id,nombre')
                ->withCount('planes')
                ->orderBy('nombre')
                ->get()
                ->map(fn (Carrera $carrera) => [
                    'id' => $carrera->id,
                    'clave' => $carrera->clave,
                    'nombre' => $carrera->nombre,
                    'nivel' => $carrera->nivelEstudios?->nombre,
                    'clave_sat' => $carrera->clave_sat,
                    'planes_count' => $carrera->planes_count,
                ]),
            'puedeEditar' => $request->user()->can('editar-catalogo-academico'),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Academico/Carreras/Formulario', [
            'carrera' => null,
            'documentosSeleccionados' => [],
            ...$this->catalogos(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $datos = $this->validar($request);
        $documentos = $datos['documentos'] ?? [];
        unset($datos['documentos']);

        $carrera = Carrera::create($datos);
        $carrera->documentos()->sync($documentos);

        return redirect()->route('tenant.academico.carreras.index')->with('exito', 'Carrera creada.');
    }

    public function edit(Carrera $carrera): Response
    {
        return Inertia::render('Academico/Carreras/Formulario', [
            'carrera' => $carrera->only([
                'id', 'identificador', 'clave', 'nombre', 'nivel_estudios_id',
                'clave_sat', 'objetivo', 'imagen_url',
            ]),
            'documentosSeleccionados' => $carrera->documentos()->pluck('documentos_requeridos.id'),
            ...$this->catalogos(),
        ]);
    }

    public function update(Request $request, Carrera $carrera): RedirectResponse
    {
        $datos = $this->validar($request, $carrera->id);
        $documentos = $datos['documentos'] ?? [];
        unset($datos['documentos']);

        $carrera->update($datos);
        $carrera->documentos()->sync($documentos);

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
            'nivel_estudios_id' => ['required', 'integer'],
            'clave_sat' => ['nullable', 'string', 'max:15'],
            'objetivo' => ['nullable', 'string'],
            'imagen_url' => ['nullable', 'string', 'max:255'],
            'documentos' => ['array'],
            'documentos.*' => ['integer'],
        ], [], [
            'nivel_estudios_id' => 'nivel de estudios',
            'clave_sat' => 'clave SAT',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function catalogos(): array
    {
        return [
            'niveles' => NivelEstudio::query()->orderBy('orden')->get(['id', 'nombre']),
            'documentos' => DocumentoRequerido::query()->orderBy('nombre')->get(['id', 'nombre', 'obligatorio']),
        ];
    }
}
