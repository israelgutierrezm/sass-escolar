<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Academico\Area;
use App\Models\Academico\Asignatura;
use App\Models\Academico\ClasificacionAsignatura;
use App\Models\Academico\Descriptor;
use App\Models\Academico\PlanMateria;
use App\Models\Academico\TipoAsignatura;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
        $filtros = [
            'busqueda' => trim((string) $request->query('busqueda', '')),
            'tipo_asignatura_id' => $request->query('tipo_asignatura_id'),
            'clasificacion_id' => $request->query('clasificacion_id'),
            'area_id' => $request->query('area_id'),
        ];

        return Inertia::render('Academico/Asignaturas/Index', [
            'asignaturas' => Asignatura::query()
                ->with(['tipoAsignatura:id,nombre', 'clasificacion:id,nombre', 'area:id,nombre'])
                ->withCount('planMaterias')
                ->when($filtros['busqueda'] !== '', function ($query) use ($filtros) {
                    $termino = "%{$filtros['busqueda']}%";

                    $query->where(fn ($q) => $q
                        ->where('nombre', 'like', $termino)
                        ->orWhere('clave', 'like', $termino));
                })
                ->when($filtros['tipo_asignatura_id'], fn ($q, $v) => $q->where('tipo_asignatura_id', $v))
                ->when($filtros['clasificacion_id'], fn ($q, $v) => $q->where('clasificacion_id', $v))
                ->when($filtros['area_id'], fn ($q, $v) => $q->where('area_id', $v))
                ->orderBy('nombre')
                ->paginate(10)
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
            'filtros' => $filtros,
            'tiposAsignatura' => TipoAsignatura::query()->orderBy('id')->get(['id', 'nombre']),
            'clasificaciones' => ClasificacionAsignatura::query()->orderBy('nombre')->get(['id', 'nombre']),
            'areas' => Area::query()->orderBy('nombre')->get(['id', 'nombre']),
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
        $datos = $this->validar($request);

        $asignatura = Asignatura::create($datos);

        $asignatura->descriptores()->sync($this->pivoteDescriptores($datos['descriptores'] ?? []));

        return redirect()
            ->route('tenant.academico.asignaturas.edit', $asignatura)
            ->with('exito', 'Asignatura creada. Ahora puedes subir sus imágenes de diseño.');
    }

    public function edit(Asignatura $asignatura): Response
    {
        $asignatura->load('descriptores');

        return Inertia::render('Academico/Asignaturas/Formulario', [
            'asignatura' => [
                ...$asignatura->only([
                    'id', 'identificador', 'clave', 'nombre', 'creditos', 'tipo_asignatura_id',
                    'clasificacion_id', 'area_id', 'horas_teoria', 'horas_practica',
                    'horas_acompanamiento', 'horas_independientes',
                ]),
                'descriptores' => $asignatura->descriptores->map(fn (Descriptor $d) => [
                    'descriptor_id' => $d->id,
                    'nombre' => $d->nombre,
                    'contenido' => $d->pivot->contenido,
                ])->values(),
                'imagenes' => $asignatura->urlsDiseno(),
            ],
            ...$this->catalogos(),
        ]);
    }

    public function update(Request $request, Asignatura $asignatura): RedirectResponse
    {
        $datos = $this->validar($request, $asignatura->id);

        $asignatura->update($datos);
        $asignatura->descriptores()->sync($this->pivoteDescriptores($datos['descriptores'] ?? []));

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
            // Los descriptores son ahora bloques de texto enriquecido tomados
            // del catálogo: cada uno trae su id y su contenido (HTML) propio de
            // ESTA asignatura. Las columnas objetivos/bibliografía siguen en la
            // tabla pero ya no se capturan aquí.
            'descriptores' => ['array'],
            'descriptores.*.descriptor_id' => ['required', 'integer', Rule::exists('descriptores', 'id')->whereNull('deleted_at')],
            'descriptores.*.contenido' => ['nullable', 'string'],
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
     * Traduce la lista `[{descriptor_id, contenido}]` del formulario al formato
     * que espera `sync()`: `[descriptor_id => ['contenido' => ...]]`. Descarta
     * duplicados quedándose con el último (si el front mandara dos veces el
     * mismo descriptor, gana el contenido más reciente).
     *
     * @param  array<int, array{descriptor_id: int, contenido?: string|null}>  $descriptores
     * @return array<int, array{contenido: string|null}>
     */
    private function pivoteDescriptores(array $descriptores): array
    {
        return collect($descriptores)
            ->mapWithKeys(fn (array $d) => [$d['descriptor_id'] => ['contenido' => $d['contenido'] ?? null]])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function catalogos(): array
    {
        return [
            // El Tipo queda en cuatro fijas (Obligatoria, Optativa, Adicional,
            // Complementaria); se ordena por id para respetar ese orden y no
            // alfabetizarlo.
            'tiposAsignatura' => TipoAsignatura::query()->orderBy('id')->get(['id', 'nombre']),
            'clasificaciones' => ClasificacionAsignatura::query()->orderBy('nombre')->get(['id', 'nombre']),
            'areas' => Area::query()->orderBy('nombre')->get(['id', 'nombre']),
            'descriptores' => Descriptor::query()->orderBy('id')->get(['id', 'nombre']),
        ];
    }

    /**
     * Las tres imágenes de diseño se suben por separado, después de crear la
     * asignatura (necesitan su id), igual que la foto de una persona. Cada una
     * a su ranura: materia, miniatura o portada.
     */
    public function subirImagen(Request $request, Asignatura $asignatura, string $tipo): RedirectResponse
    {
        $columna = $this->columnaDeImagen($tipo);

        $request->validate([
            'imagen' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ], [
            'imagen.image' => 'El archivo debe ser una imagen.',
            'imagen.max' => 'La imagen no puede pasar de 4 MB.',
        ]);

        $anterior = $asignatura->{$columna};

        $asignatura->update([$columna => $request->file('imagen')->store('asignaturas', 'local')]);

        if ($anterior !== null && $anterior !== $asignatura->{$columna}) {
            Storage::disk('local')->delete($anterior);
        }

        return back()->with('exito', 'Imagen actualizada.');
    }

    public function quitarImagen(Asignatura $asignatura, string $tipo): RedirectResponse
    {
        $columna = $this->columnaDeImagen($tipo);

        if ($asignatura->{$columna} !== null) {
            Storage::disk('local')->delete($asignatura->{$columna});
            $asignatura->update([$columna => null]);
        }

        return back()->with('exito', 'Imagen eliminada.');
    }

    public function mostrarImagen(Asignatura $asignatura, string $tipo): StreamedResponse
    {
        $columna = $this->columnaDeImagen($tipo);

        abort_if($asignatura->{$columna} === null, 404);
        abort_unless(Storage::disk('local')->exists($asignatura->{$columna}), 404);

        return Storage::disk('local')->response($asignatura->{$columna});
    }

    /** Traduce la ranura pública (materia/miniatura/portada) a su columna. */
    private function columnaDeImagen(string $tipo): string
    {
        return match ($tipo) {
            'materia' => 'imagen_materia_url',
            'miniatura' => 'imagen_miniatura_url',
            'portada' => 'foto_portada_url',
            default => abort(404),
        };
    }
}
