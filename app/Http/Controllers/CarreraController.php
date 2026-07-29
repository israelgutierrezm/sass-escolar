<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Academico\Carrera;
use App\Models\Academico\NivelEstudio;
use App\Models\Admisiones\DocumentoRequerido;
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
                    'clave_sat' => $carrera->clave_sat,
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
                'clave_sat', 'imagen_url',
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
            // Ya es catálogo TENANT (misma conexión), así que se puede validar
            // que exista de verdad, cosa que con la landlord no se hacía.
            'nivel_estudios_id' => ['required', 'integer', Rule::exists('niveles_estudio', 'id')->whereNull('deleted_at')],
            'clave_sat' => ['nullable', 'string', 'max:15'],
            // «Objetivo» se retiró del formulario a pedido del cliente. La
            // columna se conserva por si vuelve, pero ya no se captura aquí.
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
            // Cada nivel sugiere su ClaveProdServ del SAT (la asigna el SAT según
            // el nivel de estudios); el formulario la autollena al elegirlo.
            'niveles' => NivelEstudio::query()->orderBy('orden')->get(['id', 'nombre', 'clave'])
                ->map(fn (NivelEstudio $n) => [
                    'id' => $n->id,
                    'nombre' => $n->nombre,
                    'clave_sat_sugerida' => self::claveSatDeNivel($n->clave),
                ]),
            'documentos' => DocumentoRequerido::query()->orderBy('nombre')->get(['id', 'nombre', 'obligatorio']),
        ];
    }

    /**
     * ClaveProdServ del SAT según el nivel: el Técnico Superior Universitario
     * lleva 86121803 y todos los demás (licenciatura, maestría, especialidad,
     * doctorado, profesional asociado) 86121804. Cubre la clave nueva («84») y
     * la vieja («tecnico_superior») para funcionar en cualquier escuela.
     */
    private static function claveSatDeNivel(?string $claveNivel): string
    {
        return in_array($claveNivel, ['84', 'tecnico_superior'], true) ? '86121803' : '86121804';
    }
}
