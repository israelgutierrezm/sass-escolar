<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Academico\Campus;
use App\Models\Academico\Carrera;
use App\Models\Academico\Modalidad;
use App\Models\Academico\Oferta;
use App\Models\Academico\PlanEstudio;
use App\Models\Academico\Turno;
use App\Models\Admisiones\MatriculaOferta;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
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
        // La modalidad se guarda como clave; para mostrarla se resuelve su
        // nombre del catálogo (una clave como «en_linea» no se enseña cruda).
        $nombresModalidad = Modalidad::query()->pluck('nombre', 'clave');

        $filtros = [
            'busqueda' => trim((string) $request->query('busqueda', '')),
            'campus_id' => $request->query('campus_id'),
            'modalidad' => $request->query('modalidad'),
            'turno_id' => $request->query('turno_id'),
            'estatus' => $request->query('estatus'),
        ];

        return Inertia::render('Academico/Ofertas/Index', [
            'ofertas' => Oferta::query()
                ->with(['carrera:id,nombre', 'plan:id,nombre,clave', 'campus:id,nombre', 'turno:id,nombre'])
                ->withCount('matriculas')
                // La búsqueda cae sobre la carrera y el plan (por su nombre),
                // que es como la gente reconoce una oferta.
                ->when($filtros['busqueda'] !== '', fn ($q) => $q->where(fn ($sub) => $sub
                    ->whereHas('carrera', fn ($c) => $c->where('nombre', 'like', "%{$filtros['busqueda']}%"))
                    ->orWhereHas('plan', fn ($p) => $p->where('nombre', 'like', "%{$filtros['busqueda']}%")
                        ->orWhere('clave', 'like', "%{$filtros['busqueda']}%"))))
                ->when($filtros['campus_id'], fn ($q, $v) => $q->where('campus_id', $v))
                ->when($filtros['modalidad'], fn ($q, $v) => $q->where('modalidad', $v))
                ->when($filtros['turno_id'], fn ($q, $v) => $q->where('turno_id', $v))
                ->when($filtros['estatus'], fn ($q, $v) => $q->where('estatus', $v))
                ->orderBy('carrera_id')
                ->paginate(10)
                ->withQueryString()
                ->through(fn (Oferta $oferta) => [
                    'id' => $oferta->id,
                    'carrera' => $oferta->carrera?->nombre,
                    'plan' => $oferta->plan?->nombre,
                    'plan_clave' => $oferta->plan?->clave,
                    'campus' => $oferta->campus?->nombre,
                    'turno' => $oferta->turno?->nombre,
                    'modalidad' => $nombresModalidad[$oferta->modalidad] ?? $oferta->modalidad,
                    'estatus' => $oferta->estatus,
                    'matriculas_count' => $oferta->matriculas_count,
                ]),
            'filtros' => $filtros,
            'campus' => Campus::query()->orderBy('nombre')->get(['id', 'nombre']),
            'turnos' => Turno::query()->orderBy('nombre')->get(['id', 'nombre']),
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
     * Alta con FAN-OUT: una misma carrera+plan puede ofertarse en varios campus,
     * modalidades y turnos a la vez. En vez de obligar a capturar la oferta N
     * veces, se eligen los conjuntos y se genera una Oferta CONCRETA por
     * combinación. Cada Oferta sigue teniendo un solo campus/turno/modalidad, así
     * que nada de lo que depende de eso —matriculación, pagos, resolutores
     * fiscales— cambia; solo se crea en lote.
     */
    public function store(Request $request): RedirectResponse
    {
        $datos = $this->validarFanout($request);

        // Sin turnos elegidos, se genera con turno nulo (la oferta puede no
        // tener turno). Con varios, uno por turno.
        $turnos = empty($datos['turno_ids']) ? [null] : $datos['turno_ids'];

        $creadas = 0;
        $omitidas = 0;

        foreach ($datos['campus_ids'] as $campusId) {
            foreach ($turnos as $turnoId) {
                foreach ($datos['modalidades'] as $modalidad) {
                    // La misma combinación no se duplica: se cuenta como omitida
                    // y se sigue, para que un choque no aborte todo el lote.
                    $existe = Oferta::query()
                        ->where('carrera_id', $datos['carrera_id'])
                        ->where('plan_id', $datos['plan_id'])
                        ->where('campus_id', $campusId)
                        ->where('modalidad', $modalidad)
                        ->when($turnoId === null, fn ($q) => $q->whereNull('turno_id'), fn ($q) => $q->where('turno_id', $turnoId))
                        ->exists();

                    if ($existe) {
                        $omitidas++;

                        continue;
                    }

                    Oferta::create([
                        'carrera_id' => $datos['carrera_id'],
                        'plan_id' => $datos['plan_id'],
                        'campus_id' => $campusId,
                        'turno_id' => $turnoId,
                        'modalidad' => $modalidad,
                        'estatus' => $datos['estatus'],
                    ]);

                    $creadas++;
                }
            }
        }

        $mensaje = $creadas === 1 ? 'Se creó 1 oferta.' : "Se crearon {$creadas} ofertas.";

        $respuesta = redirect()->route('tenant.academico.ofertas.index')->with('exito', $mensaje);

        // Lo que ya existía no es un error, pero conviene decirlo: quien creó el
        // lote esperaba N y salieron menos.
        if ($omitidas > 0) {
            $respuesta->with('advertencia', "Se omitieron {$omitidas} porque ya existían.");
        }

        return $respuesta;
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
     * Validación del ALTA en lote: carrera+plan únicos, y conjuntos de campus,
     * modalidades y turnos.
     *
     * @return array<string, mixed>
     */
    private function validarFanout(Request $request): array
    {
        $modalidades = Modalidad::query()->pluck('clave')->all();

        $datos = $request->validate([
            'carrera_id' => ['required', 'integer', Rule::exists('carreras', 'id')->whereNull('deleted_at')],
            'plan_id' => ['required', 'integer', Rule::exists('planes_estudio', 'id')->whereNull('deleted_at')],
            'campus_ids' => ['required', 'array', 'min:1'],
            'campus_ids.*' => ['integer', Rule::exists('campus', 'id')->whereNull('deleted_at')],
            'turno_ids' => ['array'],
            'turno_ids.*' => ['integer', Rule::exists('turnos', 'id')->whereNull('deleted_at')],
            'modalidades' => ['required', 'array', 'min:1'],
            'modalidades.*' => [Rule::in($modalidades)],
            'estatus' => ['required', Rule::in(['abierta', 'cerrada'])],
        ], [], [
            'carrera_id' => 'carrera',
            'plan_id' => 'plan de estudios',
            'campus_ids' => 'campus',
            'modalidades' => 'modalidades',
        ]);

        $this->exigirPlanDeLaCarrera((int) $datos['plan_id'], (int) $datos['carrera_id']);

        return $datos;
    }

    /**
     * @return array<string, mixed>
     */
    private function validar(Request $request, ?int $id = null): array
    {
        $modalidades = Modalidad::query()->pluck('clave')->all();

        $datos = $request->validate([
            'carrera_id' => ['required', 'integer', Rule::exists('carreras', 'id')->whereNull('deleted_at')],
            'plan_id' => ['required', 'integer', Rule::exists('planes_estudio', 'id')->whereNull('deleted_at')],
            'campus_id' => ['required', 'integer', Rule::exists('campus', 'id')->whereNull('deleted_at')],
            // Ahora la modalidad sale del catálogo `modalidades` (se guarda su
            // clave), no de un enum fijo. El turno ya no forma parte de la oferta.
            'modalidad' => ['required', Rule::in($modalidades)],
            'estatus' => ['required', Rule::in(['abierta', 'cerrada'])],
        ], [], [
            'carrera_id' => 'carrera',
            'plan_id' => 'plan de estudios',
            'campus_id' => 'campus',
        ]);

        $this->validarCoherencia($request, $datos, $id);

        return $datos;
    }

    private function exigirPlanDeLaCarrera(int $planId, int $carreraId): void
    {
        $plan = PlanEstudio::find($planId);

        if ($plan !== null && $plan->carrera_id !== $carreraId) {
            throw ValidationException::withMessages([
                'plan_id' => 'El plan seleccionado no pertenece a esa carrera.',
            ]);
        }
    }

    /**
     * Dos reglas que el esquema no puede expresar solo con FKs:
     *  1. El plan debe pertenecer a la carrera elegida.
     *  2. No puede repetirse la misma combinación carrera+plan+campus+modalidad
     *     (el turno ya no forma parte de la oferta).
     *
     * @param  array<string, mixed>  $datos
     */
    private function validarCoherencia(Request $request, array $datos, ?int $id): void
    {
        $this->exigirPlanDeLaCarrera((int) $datos['plan_id'], (int) $datos['carrera_id']);

        $duplicada = Oferta::query()
            ->where('carrera_id', $datos['carrera_id'])
            ->where('plan_id', $datos['plan_id'])
            ->where('campus_id', $datos['campus_id'])
            ->where('modalidad', $datos['modalidad'])
            ->when($id !== null, fn ($q) => $q->whereKeyNot($id))
            ->exists();

        if ($duplicada) {
            throw ValidationException::withMessages([
                'campus_id' => 'Ya existe esa combinación de carrera, plan, campus y modalidad.',
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
            // Del catálogo: se ofrece por clave (lo que se guarda) y nombre.
            'modalidades' => Modalidad::query()->orderBy('nombre')->get(['clave', 'nombre']),
        ];
    }
}
