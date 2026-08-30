<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Academico\Campus;
use App\Models\Academico\Modalidad;
use App\Models\Academico\Oferta;
use App\Models\Academico\PlanEstudio;
use App\Models\Academico\ProgramaAcademico;
use App\Models\Admisiones\MatriculaOferta;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * La oferta: qué se imparte y dónde.
 *
 * Es la combinación programa académico + plan + campus —y solo eso la delimita— y la
 * unidad a la que se matriculan los alumnos. De aquí depende todo el CRM: sin
 * oferta abierta, un aspirante no puede convertirse en alumno. La `modalidad`
 * es un atributo OPCIONAL (no distingue una oferta de otra) y el turno se
 * administra en los grupos, no aquí.
 */
class OfertaController extends Controller
{
    public function index(Request $request): Response
    {
        // La modalidad se guarda como clave; para mostrarla se resuelve su
        // nombre del catálogo (una clave como «en_linea» no se enseña cruda).
        $nombresModalidad = Modalidad::query()->pluck('nombre', 'clave');

        $filtros = [
            'busqueda' => trim((string) $request->query('busqueda', '')),
            'campus_id' => $request->query('campus_id'),
            'modalidad' => $request->query('modalidad'),
            'estatus' => $request->query('estatus'),
        ];

        return Inertia::render('Academico/Ofertas/Index', [
            'ofertas' => Oferta::query()
                ->with(['programaAcademico:id,nombre', 'plan:id,nombre,clave', 'campus:id,nombre'])
                ->withCount('matriculas')
                // La búsqueda cae sobre el programa académico y el plan (por su nombre),
                // que es como la gente reconoce una oferta.
                ->when($filtros['busqueda'] !== '', fn ($q) => $q->where(fn ($sub) => $sub
                    ->whereHas('programaAcademico', fn ($c) => $c->where('nombre', 'like', "%{$filtros['busqueda']}%"))
                    ->orWhereHas('plan', fn ($p) => $p->where('nombre', 'like', "%{$filtros['busqueda']}%")
                        ->orWhere('clave', 'like', "%{$filtros['busqueda']}%"))))
                ->when($filtros['campus_id'], fn ($q, $v) => $q->where('campus_id', $v))
                ->when($filtros['modalidad'], fn ($q, $v) => $q->where('modalidad', $v))
                ->when($filtros['estatus'], fn ($q, $v) => $q->where('estatus', $v))
                ->orderBy('programa_academico_id')
                ->paginate(10)
                ->withQueryString()
                ->through(fn (Oferta $oferta) => [
                    'id' => $oferta->id,
                    'programa_academico' => $oferta->programaAcademico?->nombre,
                    'plan' => $oferta->plan?->nombre,
                    'plan_clave' => $oferta->plan?->clave,
                    'campus' => $oferta->campus?->nombre,
                    'modalidad' => $oferta->modalidad === null
                        ? null
                        : ($nombresModalidad[$oferta->modalidad] ?? $oferta->modalidad),
                    'estatus' => $oferta->estatus,
                    'matriculas_count' => $oferta->matriculas_count,
                ]),
            'filtros' => $filtros,
            'campus' => Campus::query()->orderBy('nombre')->get(['id', 'nombre']),
            'modalidades' => Modalidad::query()->orderBy('nombre')->get(['clave', 'nombre']),
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

    /**
     * Alta con FAN-OUT por campus: una mismo programa académico+plan puede ofertarse en
     * varios campus a la vez. Se elige el conjunto de campus y se genera una
     * oferta por cada uno. La modalidad (opcional) se aplica a todas.
     */
    public function store(Request $request): RedirectResponse
    {
        $datos = $this->validarFanout($request);

        $creadas = 0;
        $omitidas = 0;

        foreach ($datos['campus_ids'] as $campusId) {
            // La misma combinación no se duplica: se cuenta como omitida y se
            // sigue, para que un choque no aborte todo el lote.
            $existe = Oferta::query()
                ->where('programa_academico_id', $datos['programa_academico_id'])
                ->where('plan_id', $datos['plan_id'])
                ->where('campus_id', $campusId)
                ->exists();

            if ($existe) {
                $omitidas++;

                continue;
            }

            Oferta::create([
                'programa_academico_id' => $datos['programa_academico_id'],
                'plan_id' => $datos['plan_id'],
                'campus_id' => $campusId,
                'modalidad' => $datos['modalidad'] ?? null,
                'estatus' => $datos['estatus'],
            ]);

            $creadas++;
        }

        $mensaje = $creadas === 1 ? 'Se creó 1 oferta.' : "Se crearon {$creadas} ofertas.";

        $respuesta = redirect()->route('tenant.academico.ofertas.index')->with('exito', $mensaje);

        if ($omitidas > 0) {
            $respuesta->with('advertencia', "Se omitieron {$omitidas} porque ya existían.");
        }

        return $respuesta;
    }

    public function edit(Oferta $oferta): Response
    {
        return Inertia::render('Academico/Ofertas/Formulario', [
            'oferta' => $oferta->only(['id', 'programa_academico_id', 'plan_id', 'campus_id', 'modalidad', 'estatus']),
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
     * Validación del ALTA en lote: programa académico+plan, el conjunto de campus, y la
     * modalidad opcional que se aplicará a todas.
     *
     * @return array<string, mixed>
     */
    private function validarFanout(Request $request): array
    {
        $modalidades = Modalidad::query()->pluck('clave')->all();

        $datos = $request->validate([
            'programa_academico_id' => ['required', 'integer', Rule::exists('programas_academicos', 'id')->whereNull('deleted_at')],
            'plan_id' => ['required', 'integer', Rule::exists('planes_estudio', 'id')->whereNull('deleted_at')],
            'campus_ids' => ['required', 'array', 'min:1'],
            'campus_ids.*' => ['integer', Rule::exists('campus', 'id')->whereNull('deleted_at')],
            'modalidad' => ['nullable', Rule::in($modalidades)],
            'estatus' => ['required', Rule::in(['abierta', 'cerrada'])],
        ], [], [
            'programa_academico_id' => 'programa_academico',
            'plan_id' => 'plan de estudios',
            'campus_ids' => 'campus',
        ]);

        $this->exigirPlanDeLaProgramaAcademico((int) $datos['plan_id'], (int) $datos['programa_academico_id']);

        return $datos;
    }

    /**
     * @return array<string, mixed>
     */
    private function validar(Request $request, ?int $id = null): array
    {
        $modalidades = Modalidad::query()->pluck('clave')->all();

        $datos = $request->validate([
            'programa_academico_id' => ['required', 'integer', Rule::exists('programas_academicos', 'id')->whereNull('deleted_at')],
            'plan_id' => ['required', 'integer', Rule::exists('planes_estudio', 'id')->whereNull('deleted_at')],
            'campus_id' => ['required', 'integer', Rule::exists('campus', 'id')->whereNull('deleted_at')],
            // La modalidad es opcional y no delimita la oferta; solo la describe.
            'modalidad' => ['nullable', Rule::in($modalidades)],
            'estatus' => ['required', Rule::in(['abierta', 'cerrada'])],
        ], [], [
            'programa_academico_id' => 'programa_academico',
            'plan_id' => 'plan de estudios',
            'campus_id' => 'campus',
        ]);

        $this->validarCoherencia($datos, $id);

        return $datos;
    }

    private function exigirPlanDeLaProgramaAcademico(int $planId, int $programaAcademicoId): void
    {
        $plan = PlanEstudio::find($planId);

        if ($plan !== null && $plan->programa_academico_id !== $programaAcademicoId) {
            throw ValidationException::withMessages([
                'plan_id' => 'El plan seleccionado no pertenece a ese programa académico.',
            ]);
        }
    }

    /**
     * Dos reglas que el esquema no expresa solo con FKs:
     *  1. El plan debe pertenecer al programa académico elegida.
     *  2. No puede repetirse la misma combinación programa académico+plan+campus (lo único
     *     que delimita una oferta).
     *
     * @param  array<string, mixed>  $datos
     */
    private function validarCoherencia(array $datos, ?int $id): void
    {
        $this->exigirPlanDeLaProgramaAcademico((int) $datos['plan_id'], (int) $datos['programa_academico_id']);

        $duplicada = Oferta::query()
            ->where('programa_academico_id', $datos['programa_academico_id'])
            ->where('plan_id', $datos['plan_id'])
            ->where('campus_id', $datos['campus_id'])
            ->when($id !== null, fn ($q) => $q->whereKeyNot($id))
            ->exists();

        if ($duplicada) {
            throw ValidationException::withMessages([
                'campus_id' => 'Ya existe esa combinación de programa académico, plan y campus.',
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function catalogos(): array
    {
        return [
            'programas_academicos' => ProgramaAcademico::query()->orderBy('nombre')->get(['id', 'nombre']),
            // Se envían con su programa académico para poder filtrar el selector en el front.
            'planes' => PlanEstudio::query()->orderBy('nombre')->get(['id', 'nombre', 'clave', 'programa_academico_id']),
            'campus' => Campus::query()->orderBy('nombre')->get(['id', 'nombre']),
            // Del catálogo: se ofrece por clave (lo que se guarda) y nombre. Opcional.
            'modalidades' => Modalidad::query()->orderBy('nombre')->get(['clave', 'nombre']),
        ];
    }
}
