<?php

declare(strict_types=1);

namespace App\Http\Controllers\Rh;

use App\Http\Controllers\Controller;
use App\Models\Academico\Campus;
use App\Models\Nomina\ConceptoNomina;
use App\Models\Nomina\PeriodoNomina;
use App\Models\Nomina\ReciboConcepto;
use App\Models\Nomina\ReciboNomina;
use App\Services\Nomina\CalculadoraNomina;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Los periodos de nómina y sus recibos.
 *
 * Detrás de `gestionar-percepciones`, igual que los sueldos: aquí se ven los
 * importes de todo el personal junto, que es el dato más sensible del sistema.
 */
class NominaController extends Controller
{
    public function __construct(private readonly CalculadoraNomina $calculadora) {}

    public function index(): Response
    {
        return Inertia::render('Rh/Periodos', [
            'periodos' => PeriodoNomina::query()
                ->with('campus:id,nombre')
                ->withCount('recibos')
                ->withSum('recibos', 'neto')
                ->orderByDesc('fecha_inicio')
                ->paginate(20)
                ->through(fn (PeriodoNomina $p) => [
                    'id' => $p->id,
                    'nombre' => $p->nombre,
                    'inicio' => $p->fecha_inicio?->toDateString(),
                    'fin' => $p->fecha_fin?->toDateString(),
                    'pago' => $p->fecha_pago?->toDateString(),
                    'campus' => $p->campus?->nombre,
                    'estado' => $p->estado,
                    'recibos' => $p->recibos_count,
                    'neto' => $p->recibos_sum_neto,
                ]),
            'campus' => Campus::query()->orderBy('nombre')->get(['id', 'nombre']),
        ]);
    }

    public function guardar(Request $peticion): RedirectResponse
    {
        $datos = $peticion->validate([
            'nombre' => ['required', 'string', 'max:120'],
            'fecha_inicio' => ['required', 'date'],
            'fecha_fin' => ['required', 'date', 'after_or_equal:fecha_inicio'],
            'fecha_pago' => ['nullable', 'date'],
            'campus_id' => ['nullable', 'integer', Rule::exists('campus', 'id')],
            'notas' => ['nullable', 'string', 'max:1000'],
        ], [
            'fecha_fin.after_or_equal' => 'El periodo no puede terminar antes de empezar.',
        ]);

        $periodo = PeriodoNomina::create($datos + ['estado' => PeriodoNomina::ABIERTO]);

        /*
         * Los traslapes NO se prohíben —una quincena y un aguinaldo
         * extraordinario se enciman de forma legítima— pero cuentan las MISMAS
         * checadas, así que se avisa. Descubrirlo en el importe es peor.
         */
        $encimados = PeriodoNomina::query()
            ->whereKeyNot($periodo->id)
            ->queSeEncimanCon($datos['fecha_inicio'], $datos['fecha_fin'])
            ->count();

        return redirect("/rh/nomina/{$periodo->id}")->with(
            $encimados > 0 ? 'error' : 'exito',
            $encimados > 0
                ? "Periodo creado, pero se encima con {$encimados} periodo(s). Ojo: las horas checadas se contarían en los dos."
                : 'Periodo creado.',
        );
    }

    public function ver(PeriodoNomina $periodo): Response
    {
        $periodo->load('campus:id,nombre');

        $recibos = $periodo->recibos()
            ->with([
                'expediente:id,persona_id,numero_empleado',
                'expediente.persona:id,nombre,primer_apellido,segundo_apellido',
            ])
            ->get()
            ->sortBy(fn (ReciboNomina $r) => $r->expediente?->numero_empleado)
            ->values();

        return Inertia::render('Rh/Periodo', [
            'periodo' => [
                'id' => $periodo->id,
                'nombre' => $periodo->nombre,
                'inicio' => $periodo->fecha_inicio?->toDateString(),
                'fin' => $periodo->fecha_fin?->toDateString(),
                'pago' => $periodo->fecha_pago?->toDateString(),
                'campus' => $periodo->campus?->nombre,
                'estado' => $periodo->estado,
                'se_puede_tocar' => $periodo->sePuedeTocar(),
                'notas' => $periodo->notas,
            ],
            // Cuántos entrarían si se calculara ahora. Se enseña ANTES de
            // calcular para que nadie descubra al final que faltaba medio
            // personal por una adscripción sin abrir.
            'elegibles' => $this->calculadora->elegibles($periodo)->count(),
            'recibos' => $recibos->map(fn (ReciboNomina $r) => [
                'id' => $r->id,
                'persona' => $r->expediente?->persona?->nombreCompleto(),
                'numero_empleado' => $r->expediente?->numero_empleado,
                'percepciones' => $r->total_percepciones,
                'deducciones' => $r->total_deducciones,
                'neto' => $r->neto,
                'incidencias' => $r->incidencias,
            ]),
            'totales' => [
                'percepciones' => round((float) $recibos->sum(fn ($r) => (float) $r->total_percepciones), 2),
                'deducciones' => round((float) $recibos->sum(fn ($r) => (float) $r->total_deducciones), 2),
                'neto' => round((float) $recibos->sum(fn ($r) => (float) $r->neto), 2),
                'con_incidencias' => $recibos->filter(fn ($r) => filled($r->incidencias))->count(),
            ],
        ]);
    }

    public function calcular(PeriodoNomina $periodo): RedirectResponse
    {
        try {
            $resultado = $this->calculadora->calcular($periodo);
        } catch (RuntimeException $e) {
            return back(303)->with('error', $e->getMessage());
        }

        $aviso = "Se calcularon {$resultado['recibos']} recibo(s).";

        // Lo que se perdió al rehacer, dicho por su nombre: perder un descuento
        // capturado a mano en silencio es pagarle de más a alguien.
        if ($resultado['manuales_borrados'] > 0) {
            $aviso .= " Se borraron {$resultado['manuales_borrados']} renglón(es) que estaban capturados a mano.";
        }

        if ($resultado['con_incidencias'] > 0) {
            $aviso .= " {$resultado['con_incidencias']} traen incidencias: revísalos antes de cerrar.";
        }

        return back(303)->with($resultado['con_incidencias'] > 0 ? 'error' : 'exito', $aviso);
    }

    public function cerrar(PeriodoNomina $periodo): RedirectResponse
    {
        if ($periodo->estaCerrado()) {
            return back(303)->with('error', 'Ese periodo ya estaba cerrado.');
        }

        if ($periodo->recibos()->count() === 0) {
            return back(303)->with('error', 'No se puede cerrar un periodo sin recibos: calcúlalo primero.');
        }

        $periodo->update(['estado' => PeriodoNomina::CERRADO]);

        return back(303)->with('exito', 'Periodo cerrado.');
    }

    public function reabrir(PeriodoNomina $periodo): RedirectResponse
    {
        if (! $periodo->estaCerrado()) {
            return back(303)->with('error', 'Ese periodo no está cerrado.');
        }

        // Se reabre en CALCULADO y no en abierto: sus recibos siguen ahí, y
        // decir «abierto» haría creer que están por generarse.
        $periodo->update(['estado' => PeriodoNomina::CALCULADO]);

        return back(303)->with('exito', 'Periodo reabierto.');
    }

    public function recibo(PeriodoNomina $periodo, ReciboNomina $recibo): Response
    {
        abort_unless($recibo->periodo_nomina_id === $periodo->id, 404);

        $recibo->load([
            'expediente:id,persona_id,numero_empleado,banco,clabe',
            'expediente.persona:id,nombre,primer_apellido,segundo_apellido,rfc,curp,nss',
            'esquema.modalidad:id,nombre',
            'conceptos.concepto:id,nombre,naturaleza',
        ]);

        return Inertia::render('Rh/Recibo', [
            'periodo' => [
                'id' => $periodo->id,
                'nombre' => $periodo->nombre,
                'inicio' => $periodo->fecha_inicio?->toDateString(),
                'fin' => $periodo->fecha_fin?->toDateString(),
                'se_puede_tocar' => $periodo->sePuedeTocar(),
            ],
            'recibo' => [
                'id' => $recibo->id,
                'persona' => $recibo->expediente?->persona?->nombreCompleto(),
                'numero_empleado' => $recibo->expediente?->numero_empleado,
                'rfc' => $recibo->expediente?->persona?->rfc,
                'nss' => $recibo->expediente?->persona?->nss,
                'banco' => $recibo->expediente?->banco,
                'clabe' => $recibo->expediente?->clabe,
                // De dónde salieron los números. Sin esto, explicar un importe
                // obliga a reconstruir qué sueldo regía.
                'esquema' => $recibo->esquema?->modalidad?->nombre,
                'esquema_desde' => $recibo->esquema?->vigente_desde?->toDateString(),
                'percepciones' => $recibo->total_percepciones,
                'deducciones' => $recibo->total_deducciones,
                'neto' => $recibo->neto,
                'incidencias' => $recibo->incidencias,
            ],
            'renglones' => $recibo->conceptos->sortBy('orden')->values()->map(fn (ReciboConcepto $c) => [
                'id' => $c->id,
                'concepto' => $c->concepto?->nombre,
                'suma' => $c->concepto?->naturaleza === ConceptoNomina::PERCEPCION,
                'importe' => $c->importe,
                'cantidad' => $c->cantidad,
                'detalle' => $c->detalle,
                'manual' => $c->manual,
            ]),
            'conceptos' => ConceptoNomina::query()->activos()->get(['id', 'nombre', 'naturaleza']),
        ]);
    }

    /** Agrega un renglón a mano: un préstamo, un bono que nadie calcula. */
    public function agregarRenglon(Request $peticion, PeriodoNomina $periodo, ReciboNomina $recibo): RedirectResponse
    {
        abort_unless($recibo->periodo_nomina_id === $periodo->id, 404);

        if ($periodo->estaCerrado()) {
            return back(303)->with('error', 'Ese periodo está cerrado.');
        }

        $datos = $peticion->validate([
            'concepto_nomina_id' => ['required', Rule::exists('conceptos_nomina', 'id')],
            'importe' => ['required', 'numeric', 'min:0.01', 'max:9999999'],
            'detalle' => ['nullable', 'string', 'max:200'],
        ], [
            'importe.min' => 'Un renglón en cero no dice nada: no se agrega.',
        ]);

        $recibo->conceptos()->create($datos + [
            'manual' => true,
            'orden' => ((int) $recibo->conceptos()->max('orden')) + 10,
        ]);

        $recibo->recalcularTotales();

        return back(303)->with('exito', 'Renglón agregado.');
    }

    public function quitarRenglon(PeriodoNomina $periodo, ReciboNomina $recibo, ReciboConcepto $renglon): RedirectResponse
    {
        abort_unless($recibo->periodo_nomina_id === $periodo->id, 404);
        abort_unless($renglon->recibo_nomina_id === $recibo->id, 404);

        if ($periodo->estaCerrado()) {
            return back(303)->with('error', 'Ese periodo está cerrado.');
        }

        /*
         * Sólo los capturados a mano. Quitar uno calculado dejaría el recibo
         * diciendo algo que el esquema no dice, y al recalcular volvería a
         * aparecer: se corrige el sueldo, no el renglón.
         */
        if (! $renglon->manual) {
            return back(303)->with('error', 'Ese renglón lo puso el cálculo. Para cambiarlo, corrige el sueldo y recalcula.');
        }

        $renglon->forceDelete();
        $recibo->recalcularTotales();

        return back(303)->with('exito', 'Renglón quitado.');
    }
}
