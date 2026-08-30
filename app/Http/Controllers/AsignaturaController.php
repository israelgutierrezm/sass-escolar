<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Academico\Area;
use App\Models\Academico\Asignatura;
use App\Models\Academico\ClasificacionAsignatura;
use App\Models\Academico\PlanEstudio;
use App\Models\Academico\PlanMateria;
use App\Models\Academico\ProgramaAcademico;
use App\Models\Academico\TipoAsignatura;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Catálogo de asignaturas.
 *
 * Es catálogo PURO: la misma asignatura se reutiliza entre planes y entre
 * programas académicos. Su vida dentro de un plan concreto —la clave que sale en el acta,
 * el periodo sugerido, si es obligatoria u optativa— no vive aquí sino en
 * `plan_materias`, porque cambia de un plan a otro.
 *
 * Eso es lo que permite el tronco común: una sola "Matemáticas I" en el
 * catálogo, compartida por varios programas académicos, con distinta clave de acta en cada
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
                ->with([
                    'tipoAsignatura:id,nombre', 'clasificacion:id,nombre', 'area:id,nombre',
                    // Para el badge: cuando la asignatura vive en UN solo plan se
                    // muestra su clave; en varios, el conteo. Catálogo puro dixit.
                    'planMaterias:id,asignatura_id,plan_id', 'planMaterias.plan:id,clave',
                ])
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
                    // Clave del plan cuando es exactamente uno; null si es
                    // compartida entre varios o si aún no está en ninguno.
                    'plan_clave' => $asignatura->plan_materias_count === 1
                        ? $asignatura->planMaterias->first()?->plan?->clave
                        : null,
                ]),
            'filtros' => $filtros,
            'tiposAsignatura' => TipoAsignatura::query()->orderBy('id')->get(['id', 'nombre']),
            'clasificaciones' => ClasificacionAsignatura::query()->orderBy('nombre')->get(['id', 'nombre']),
            'areas' => Area::query()->orderBy('nombre')->get(['id', 'nombre']),
            'puedeEditar' => $request->user()->can('editar-catalogo-academico'),
        ]);
    }

    /**
     * Alta de asignatura: como toda asignatura nace ligada a un plan, primero se
     * elige programa académico → plan y de ahí se cae en el alta de la malla de ese plan
     * (la ÚNICA alta; ya no hay un formulario de asignatura aparte).
     */
    public function create(): Response
    {
        return Inertia::render('Academico/Asignaturas/ElegirPlan', [
            'programas_academicos' => ProgramaAcademico::query()->orderBy('nombre')->get(['id', 'nombre'])
                ->map(fn (ProgramaAcademico $c) => [
                    'id' => $c->id,
                    'nombre' => $c->nombre,
                    'planes' => PlanEstudio::query()->where('programa_academico_id', $c->id)->orderBy('nombre')->get(['id', 'nombre']),
                ]),
        ]);
    }

    /**
     * Editar una asignatura es editar la materia en su plan: se redirige a la
     * ficha del plan (datos, descriptores, imágenes, ubicación, requisitos,
     * evaluación en un solo lugar). Si la asignatura no está en ningún plan
     * (caso borde), no hay ficha: se pide agregarla a un plan primero.
     */
    public function edit(Asignatura $asignatura): RedirectResponse
    {
        $materia = PlanMateria::query()
            ->where('asignatura_id', $asignatura->id)
            ->orderByDesc('id')
            ->first();

        if ($materia !== null) {
            return redirect()->route('tenant.academico.planes.materias.show', [$materia->plan_id, $materia->id]);
        }

        return redirect()->route('tenant.academico.asignaturas.index')
            ->with('error', 'Esta asignatura no pertenece a ningún plan; agrégala a uno para editarla.');
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
