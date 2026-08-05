<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AcotaPorCampus;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\ControlEscolar\Ciclo;
use App\Models\Finanzas\Beca;
use App\Models\Finanzas\BecaAlumno;
use App\Models\Finanzas\BecaAlumnoMovimiento;
use App\Models\Finanzas\ConceptoPago;
use App\Services\EvaluadorBecas;
use App\Services\GeneradorAdeudos;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Becas: el catálogo y su otorgamiento.
 *
 * Una beca se define una vez con sus REGLAS (a qué conceptos aplica, si se
 * renueva por ciclo, qué pasa si el alumno se atrasa y qué promedio necesita) y
 * después se le otorga a alumnos concretos. Otorgar recalcula sus cargos
 * pendientes: quien ya pagó no se toca, pero lo que aún debe se le recompone con
 * el descuento.
 */
class BecaController extends Controller
{
    use AcotaPorCampus;

    public function __construct(
        private readonly GeneradorAdeudos $generador,
        private readonly EvaluadorBecas $evaluador,
    ) {}

    public function index(Request $request): Response
    {
        // Se busca una beca por su nombre, y se acota a las que siguen vivas:
        // el catálogo acumula las de convocatorias pasadas y ofrecerlas todas al
        // otorgar es invitar a asignar una que ya no existe.
        $filtros = [
            'busqueda' => trim((string) $request->query('busqueda', '')),
            'activo' => $request->query('activo'),
        ];

        $becas = Beca::query()
            ->with('conceptos:id,nombre')
            ->when($filtros['busqueda'] !== '', fn ($q) => $q->where(fn ($sub) => $sub
                ->where('clave', 'like', "%{$filtros['busqueda']}%")
                ->orWhere('nombre', 'like', "%{$filtros['busqueda']}%")))
            ->when($filtros['activo'], fn ($q) => $q->where('activo', true))
            ->withCount(['otorgadas as activas_count' => fn ($q) => $q->where('estatus', BecaAlumno::ACTIVA)])
            ->orderBy('nombre')
            ->get()
            ->map(fn (Beca $b) => [
                'id' => $b->id,
                'clave' => $b->clave,
                'nombre' => $b->nombre,
                'descripcion' => $b->descripcion,
                'modo' => $b->modo,
                'valor' => (float) $b->valor,
                'tope_monto' => $b->tope_monto !== null ? (float) $b->tope_monto : null,
                'conceptos' => $b->conceptos->pluck('nombre')->all(),
                'por_ciclo' => $b->por_ciclo,
                'requiere_renovacion' => $b->requiere_renovacion,
                'requiere_pago_puntual' => $b->requiere_pago_puntual,
                'dias_tolerancia' => $b->dias_tolerancia,
                'efecto_atraso' => $b->efecto_atraso,
                'promedio_minimo' => $b->promedio_minimo !== null ? (float) $b->promedio_minimo : null,
                'efecto_promedio' => $b->efecto_promedio,
                'activo' => $b->activo,
                'activas' => $b->activas_count,
            ]);

        return Inertia::render('Finanzas/Becas/Index', [
            'filtros' => $filtros,
            'becas' => $becas,
            'catalogoConceptos' => ConceptoPago::orderBy('nombre')->get(['id', 'nombre']),
            // Vigentes: una beca se otorga o se renueva sobre el ciclo que corre.
            'ciclos' => Ciclo::query()->vigentes()->orderByDesc('fecha_inicio')->get(['id', 'nombre']),
            // Cuántas becas renovables hay vivas: si no hay ninguna, la
            // herramienta de renovación no tiene sobre qué operar.
            'renovables' => BecaAlumno::query()
                ->activas()
                ->whereHas('beca', fn ($q) => $q->where('requiere_renovacion', true))
                ->count(),
            'efectosAtraso' => [
                ['valor' => Beca::ATRASO_NINGUNO, 'etiqueta' => 'No pasa nada'],
                ['valor' => Beca::ATRASO_SUSPENDE_PERIODO, 'etiqueta' => 'Ese cargo se cobra completo'],
                ['valor' => Beca::ATRASO_PIERDE, 'etiqueta' => 'Pierde la beca'],
            ],
            'efectosPromedio' => [
                ['valor' => Beca::PROMEDIO_NINGUNO, 'etiqueta' => 'No pasa nada'],
                ['valor' => Beca::PROMEDIO_NO_RENUEVA, 'etiqueta' => 'No se le renueva'],
                ['valor' => Beca::PROMEDIO_PIERDE, 'etiqueta' => 'Pierde la beca'],
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $datos = $this->validar($request);

        $beca = Beca::create($datos);
        $beca->conceptos()->sync($datos['conceptos'] ?? []);

        return back()->with('exito', 'Beca creada.');
    }

    public function update(Request $request, Beca $beca): RedirectResponse
    {
        $datos = $this->validar($request, $beca);

        $beca->update($datos);
        $beca->conceptos()->sync($datos['conceptos'] ?? []);

        return back()->with('exito', 'Beca actualizada.');
    }

    public function destroy(Beca $beca): RedirectResponse
    {
        if ($beca->otorgadas()->exists()) {
            return back()->with('error', 'No se puede eliminar: ya se le otorgó a alumnos. Desactívala para que deje de aplicar.');
        }

        $beca->delete();

        return back()->with('exito', 'Beca eliminada.');
    }

    // ---------- Otorgamiento ----------

    /** Alumnos con esta beca, y a quiénes se les puede otorgar. */
    public function show(Request $request, Beca $beca): Response
    {
        $beca->load('conceptos:id,nombre');

        $consulta = BecaAlumno::query()->where('beca_id', $beca->id);

        // Solo los becarios de sus campus: la beca es global, los alumnos no.
        $this->acotarMatriculas($consulta, $request, 'matricula');

        $otorgadas = $consulta
            ->with([
                'matricula.persona:id,nombre,primer_apellido,segundo_apellido',
                'matricula.oferta.carrera:id,nombre',
                'ciclo:id,nombre',
                'movimientos',
            ])
            ->orderByDesc('id')
            ->get()
            ->map(fn (BecaAlumno $b) => [
                'id' => $b->id,
                'alumno' => $b->matricula?->persona?->nombreCompleto(),
                'matricula' => $b->matricula?->matricula,
                'carrera' => $b->matricula?->oferta?->carrera?->nombre,
                'ciclo' => $b->ciclo?->nombre,
                'estatus' => $b->estatus,
                'vigente_desde' => $b->vigente_desde?->toDateString(),
                'vigente_hasta' => $b->vigente_hasta?->toDateString(),
                'promedio_evaluado' => $b->promedio_evaluado !== null ? (float) $b->promedio_evaluado : null,
                'motivo' => $b->motivo,
                'movimientos' => $b->movimientos->map(fn (BecaAlumnoMovimiento $m) => [
                    'accion' => $m->accion,
                    'detalle' => $m->detalle,
                    'por' => $m->realizado_por_nombre,
                    'fecha' => $m->created_at?->format('d/m/Y H:i'),
                ])->values(),
            ]);

        return Inertia::render('Finanzas/Becas/Detalle', [
            'beca' => [
                'id' => $beca->id,
                'clave' => $beca->clave,
                'nombre' => $beca->nombre,
                'descripcion' => $beca->descripcion,
                'modo' => $beca->modo,
                'valor' => (float) $beca->valor,
                'tope_monto' => $beca->tope_monto !== null ? (float) $beca->tope_monto : null,
                'conceptos' => $beca->conceptos->pluck('nombre')->all(),
                'por_ciclo' => $beca->por_ciclo,
                'requiere_renovacion' => $beca->requiere_renovacion,
                'requiere_pago_puntual' => $beca->requiere_pago_puntual,
                'dias_tolerancia' => $beca->dias_tolerancia,
                'efecto_atraso' => $beca->efecto_atraso,
                'promedio_minimo' => $beca->promedio_minimo !== null ? (float) $beca->promedio_minimo : null,
                'efecto_promedio' => $beca->efecto_promedio,
                'activo' => $beca->activo,
            ],
            'otorgadas' => $otorgadas,
            // Vigentes: una beca se otorga o se renueva sobre el ciclo que corre.
            'ciclos' => Ciclo::query()->vigentes()->orderByDesc('fecha_inicio')->get(['id', 'nombre']),
        ]);
    }

    /** Busca alumnos activos por matrícula o nombre, para otorgarles la beca. */
    public function buscarAlumnos(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));

        if (mb_strlen($q) < 2) {
            return response()->json([]);
        }

        $consulta = MatriculaOferta::query()->where('estatus', 'activo');

        // No se puede becar a quien no se administra: el buscador solo
        // encuentra alumnos de los campus del usuario.
        $this->acotarMatriculas($consulta, $request);

        $alumnos = $consulta
            ->where(function ($query) use ($q) {
                $query->where('matricula', 'like', "%{$q}%")
                    ->orWhereHas('persona', fn ($p) => $p
                        ->where('nombre', 'like', "%{$q}%")
                        ->orWhere('primer_apellido', 'like', "%{$q}%")
                        ->orWhere('segundo_apellido', 'like', "%{$q}%"));
            })
            ->with(['persona:id,nombre,primer_apellido,segundo_apellido', 'oferta.carrera:id,nombre'])
            ->limit(20)
            ->get()
            ->map(fn (MatriculaOferta $m) => [
                'id' => $m->id,
                'matricula' => $m->matricula,
                'nombre' => $m->persona?->nombreCompleto(),
                'carrera' => $m->oferta?->carrera?->nombre,
            ]);

        return response()->json($alumnos);
    }

    /**
     * Otorga la beca. Recalcula los cargos pendientes del alumno para que el
     * descuento se refleje en lo que todavía debe; lo pagado no se toca.
     */
    public function otorgar(Request $request, Beca $beca): RedirectResponse
    {
        $datos = $request->validate([
            'matricula_oferta_id' => ['required', 'integer', Rule::exists('matricula_oferta', 'id')],
            'ciclo_id' => ['nullable', 'integer', Rule::exists('ciclos', 'id')],
            'vigente_desde' => ['required', 'date'],
            'vigente_hasta' => ['nullable', 'date', 'after_or_equal:vigente_desde'],
            'promedio_evaluado' => ['nullable', 'numeric', 'min:0', 'max:10'],
            'motivo' => ['nullable', 'string', 'max:255'],
        ]);

        // El id viaja en el POST: filtrar el buscador no basta.
        $destino = MatriculaOferta::with('oferta:id,campus_id')->findOrFail($datos['matricula_oferta_id']);
        $this->autorizarMatricula($request, $destino);

        $yaTiene = BecaAlumno::query()
            ->where('beca_id', $beca->id)
            ->where('matricula_oferta_id', $datos['matricula_oferta_id'])
            ->where('ciclo_id', $datos['ciclo_id'] ?? null)
            ->exists();

        if ($yaTiene) {
            return back()->with('error', 'Ese alumno ya tiene esta beca en ese ciclo.');
        }

        $becaAlumno = BecaAlumno::create($datos + [
            'beca_id' => $beca->id,
            'estatus' => BecaAlumno::ACTIVA,
            'autorizado_por' => $request->user()?->persona_id,
        ]);

        $this->evaluador->registrar($becaAlumno, BecaAlumnoMovimiento::OTORGADA, $datos['motivo'] ?? null);

        $matricula = MatriculaOferta::find($datos['matricula_oferta_id']);
        $tocados = $matricula !== null ? $this->generador->recalcularPendientes($matricula) : 0;

        return back()->with(
            'exito',
            "Beca otorgada.".($tocados > 0 ? " Se recalcularon {$tocados} cargo(s) pendientes." : '')
        );
    }

    /** Quita la beca (la marca perdida) y recompone sus cargos pendientes. */
    public function revocar(Request $request, Beca $beca, BecaAlumno $otorgada): RedirectResponse
    {
        abort_unless($otorgada->beca_id === $beca->id, 404);
        $this->autorizarOtorgada($request, $otorgada);

        $datos = $request->validate(['motivo' => ['required', 'string', 'max:255']]);

        $this->evaluador->perder($otorgada, $datos['motivo']);

        return back()->with('exito', 'Beca revocada. Sus cargos pendientes se recalcularon sin el descuento.');
    }

    /** Renueva la beca para otro ciclo: se crea la del ciclo nuevo. */
    public function renovar(Request $request, Beca $beca, BecaAlumno $otorgada): RedirectResponse
    {
        abort_unless($otorgada->beca_id === $beca->id, 404);
        $this->autorizarOtorgada($request, $otorgada);

        $datos = $request->validate([
            'ciclo_id' => ['required', 'integer', Rule::exists('ciclos', 'id')],
            'vigente_desde' => ['required', 'date'],
            'vigente_hasta' => ['nullable', 'date', 'after_or_equal:vigente_desde'],
        ]);

        $nueva = BecaAlumno::create([
            'matricula_oferta_id' => $otorgada->matricula_oferta_id,
            'beca_id' => $beca->id,
            'ciclo_id' => $datos['ciclo_id'],
            'estatus' => BecaAlumno::ACTIVA,
            'vigente_desde' => $datos['vigente_desde'],
            'vigente_hasta' => $datos['vigente_hasta'] ?? null,
            'promedio_evaluado' => $otorgada->promedio_evaluado,
            'autorizado_por' => $request->user()?->persona_id,
            'motivo' => 'Renovación',
        ]);

        $this->evaluador->registrar($nueva, BecaAlumnoMovimiento::RENOVADA, 'Renovada desde el ciclo anterior.');

        if ($otorgada->matricula !== null) {
            $this->generador->recalcularPendientes($otorgada->matricula);
        }

        return back()->with('exito', 'Beca renovada para el ciclo nuevo.');
    }

    /**
     * Cierra un ciclo para efectos de becas: con el promedio de cada alumno
     * decide cuáles quedan por renovar, cuáles no se renuevan y cuáles se
     * pierden.
     *
     * Las que sí califican quedan en `por_renovar`, NO renovadas solas: renovar
     * una beca es autorizar un gasto y debe hacerlo una persona.
     */
    public function evaluarRenovacion(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'ciclo_id' => ['required', 'integer', Rule::exists('ciclos', 'id')],
        ]);

        $ciclo = Ciclo::findOrFail($datos['ciclo_id']);
        $r = $this->evaluador->renovarCiclo($ciclo);

        if ($r['evaluados'] === 0) {
            return back()->with('advertencia', "El ciclo «{$ciclo->nombre}» no tiene calificaciones finales capturadas: no hay con qué evaluar los promedios.");
        }

        return back()->with(
            'exito',
            "Ciclo «{$ciclo->nombre}» evaluado sobre {$r['evaluados']} alumno(s): "
            ."{$r['por_renovar']} por renovar, {$r['no_renovadas']} sin renovar, {$r['perdidas']} perdida(s)."
        );
    }

    /** Una beca otorgada solo la toca quien administra el campus del alumno. */
    private function autorizarOtorgada(Request $request, BecaAlumno $otorgada): void
    {
        $matricula = $otorgada->matricula;

        if ($matricula === null) {
            return;
        }

        $matricula->loadMissing('oferta:id,campus_id');
        $this->autorizarMatricula($request, $matricula);
    }

    /** @return array<string, mixed> */
    private function validar(Request $request, ?Beca $beca = null): array
    {
        return $request->validate([
            'clave' => ['required', 'string', 'max:50', Rule::unique('becas', 'clave')->ignore($beca?->id)],
            'nombre' => ['required', 'string', 'max:150'],
            'descripcion' => ['nullable', 'string', 'max:255'],
            'modo' => ['required', Rule::in([Beca::MODO_PORCENTAJE, Beca::MODO_MONTO_FIJO])],
            'valor' => ['required', 'numeric', 'min:0'],
            'tope_monto' => ['nullable', 'numeric', 'min:0'],
            'conceptos' => ['array'],
            'conceptos.*' => ['integer', Rule::exists('conceptos_pago', 'id')],
            'por_ciclo' => ['boolean'],
            'requiere_renovacion' => ['boolean'],
            'requiere_pago_puntual' => ['boolean'],
            'dias_tolerancia' => ['required', 'integer', 'min:0', 'max:60'],
            'efecto_atraso' => ['required', Rule::in([Beca::ATRASO_NINGUNO, Beca::ATRASO_SUSPENDE_PERIODO, Beca::ATRASO_PIERDE])],
            'promedio_minimo' => ['nullable', 'numeric', 'min:0', 'max:10'],
            'efecto_promedio' => ['required', Rule::in([Beca::PROMEDIO_NINGUNO, Beca::PROMEDIO_NO_RENUEVA, Beca::PROMEDIO_PIERDE])],
            'activo' => ['boolean'],
        ]);
    }
}
