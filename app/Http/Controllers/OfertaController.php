<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Academico\Campus;
use App\Models\Academico\Carrera;
use App\Models\Academico\Oferta;
use App\Models\Academico\PlanEstudio;
use App\Models\Academico\Turno;
use App\Models\Admisiones\MatriculaOferta;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * La oferta: qué se imparte, dónde y en qué modalidad.
 *
 * Es la combinación carrera + plan + campus (+ turno) y la unidad a la que se
 * matriculan los alumnos. De aquí depende todo el CRM: sin oferta abierta, un
 * aspirante no puede convertirse en alumno.
 */
class OfertaController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('Academico/Ofertas/Index', [
            'ofertas' => Oferta::query()
                ->with(['carrera:id,nombre', 'plan:id,nombre,clave', 'campus:id,nombre', 'turno:id,nombre'])
                ->withCount('matriculas')
                ->orderBy('carrera_id')
                ->get()
                ->map(fn (Oferta $oferta) => [
                    'id' => $oferta->id,
                    'carrera' => $oferta->carrera?->nombre,
                    'plan' => $oferta->plan?->nombre,
                    'plan_clave' => $oferta->plan?->clave,
                    'campus' => $oferta->campus?->nombre,
                    'turno' => $oferta->turno?->nombre,
                    'modalidad' => $oferta->modalidad,
                    'estatus' => $oferta->estatus,
                    'matriculas_count' => $oferta->matriculas_count,
                ]),
            'puedeEditar' => $request->user()->can('editar-catalogo-academico'),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Academico/Ofertas/Formulario', [
            'oferta' => null,
            ...$this->catalogos(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Oferta::create($this->validar($request));

        return redirect()->route('tenant.academico.ofertas.index')->with('exito', 'Oferta creada.');
    }

    public function edit(Oferta $oferta): Response
    {
        return Inertia::render('Academico/Ofertas/Formulario', [
            'oferta' => $oferta->only(['id', 'carrera_id', 'plan_id', 'campus_id', 'turno_id', 'modalidad', 'estatus']),
            ...$this->catalogos(),
        ]);
    }

    public function update(Request $request, Oferta $oferta): RedirectResponse
    {
        $oferta->update($this->validar($request, $oferta->id));

        return redirect()->route('tenant.academico.ofertas.index')->with('exito', 'Oferta actualizada.');
    }

    /**
     * Una oferta con alumnos matriculados no se elimina: para dejar de recibir
     * inscripciones se cierra (estatus), conservando el historial.
     */
    public function destroy(Oferta $oferta): RedirectResponse
    {
        if (MatriculaOferta::query()->where('oferta_id', $oferta->id)->exists()) {
            return back()->with('error', 'No se puede eliminar: hay alumnos matriculados. Ciérrala en su lugar.');
        }

        $oferta->delete();

        return back()->with('exito', 'Oferta eliminada.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validar(Request $request, ?int $id = null): array
    {
        $datos = $request->validate([
            'carrera_id' => ['required', 'integer', Rule::exists('carreras', 'id')->whereNull('deleted_at')],
            'plan_id' => ['required', 'integer', Rule::exists('planes_estudio', 'id')->whereNull('deleted_at')],
            'campus_id' => ['required', 'integer', Rule::exists('campus', 'id')->whereNull('deleted_at')],
            'turno_id' => ['nullable', 'integer', Rule::exists('turnos', 'id')->whereNull('deleted_at')],
            'modalidad' => ['required', Rule::in(['presencial', 'online', 'mixta'])],
            'estatus' => ['required', Rule::in(['abierta', 'cerrada'])],
        ], [], [
            'carrera_id' => 'carrera',
            'plan_id' => 'plan de estudios',
            'campus_id' => 'campus',
            'turno_id' => 'turno',
        ]);

        $this->validarCoherencia($request, $datos, $id);

        return $datos;
    }

    /**
     * Dos reglas que el esquema no puede expresar solo con FKs:
     *  1. El plan debe pertenecer a la carrera elegida.
     *  2. No puede repetirse la misma combinación carrera+plan+campus+turno.
     *     (El índice único existe, pero MySQL trata los NULL de turno como
     *     distintos, así que la duplicidad sin turno se valida aquí.)
     *
     * @param  array<string, mixed>  $datos
     */
    private function validarCoherencia(Request $request, array $datos, ?int $id): void
    {
        $plan = PlanEstudio::find($datos['plan_id']);

        if ($plan !== null && $plan->carrera_id !== (int) $datos['carrera_id']) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'plan_id' => 'El plan seleccionado no pertenece a esa carrera.',
            ]);
        }

        $duplicada = Oferta::query()
            ->where('carrera_id', $datos['carrera_id'])
            ->where('plan_id', $datos['plan_id'])
            ->where('campus_id', $datos['campus_id'])
            ->when(
                $datos['turno_id'] === null,
                fn ($q) => $q->whereNull('turno_id'),
                fn ($q) => $q->where('turno_id', $datos['turno_id']),
            )
            ->when($id !== null, fn ($q) => $q->whereKeyNot($id))
            ->exists();

        if ($duplicada) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'campus_id' => 'Ya existe esa combinación de carrera, plan, campus y turno.',
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function catalogos(): array
    {
        return [
            'carreras' => Carrera::query()->orderBy('nombre')->get(['id', 'nombre']),
            // Se envían con su carrera para poder filtrar el selector en el front.
            'planes' => PlanEstudio::query()->orderBy('nombre')->get(['id', 'nombre', 'clave', 'carrera_id']),
            'campus' => Campus::query()->orderBy('nombre')->get(['id', 'nombre']),
            'turnos' => Turno::query()->orderBy('nombre')->get(['id', 'nombre']),
        ];
    }
}
