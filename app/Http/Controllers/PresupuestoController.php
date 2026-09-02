<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Academico\Campus;
use App\Models\ControlEscolar\Ciclo;
use App\Models\Finanzas\CentroCosto;
use App\Models\Finanzas\Egreso;
use App\Models\Finanzas\PartidaPresupuesto;
use App\Models\Finanzas\Presupuesto;
use App\Models\Nomina\PeriodoNomina;
use App\Services\Finanzas\PresupuestoDeEgresos;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * El presupuesto de egresos y sus dimensiones.
 *
 * ── DOS permisos porque son dos oficios ────────────────────────────────────
 * `gestionar-presupuesto` decide de cuánto dispone cada área en el ciclo — eso
 * es dirección. `registrar-egresos` captura el gasto del día — eso es
 * administración. Quien captura una factura de mantenimiento no tiene por qué
 * poder subirse su propio techo.
 *
 * ── No se acota por campus ─────────────────────────────────────────────────
 * El presupuesto de la escuela se mira completo o no se mira: comparar el
 * ejercido de un plantel sin ver el de los demás no contesta ninguna de las
 * preguntas que se le hacen a un presupuesto. El CENTRO DE COSTO ya lleva el
 * campus, que es la forma correcta de partirlo aquí.
 */
class PresupuestoController extends Controller
{
    public function __construct(private readonly PresupuestoDeEgresos $presupuesto) {}

    public function index(Request $peticion): Response
    {
        $ciclo = $this->cicloElegido($peticion);

        return Inertia::render('Finanzas/Presupuesto/Index', [
            'ciclos' => Ciclo::query()->orderByDesc('id')->get(['id', 'nombre'])
                ->map(fn (Ciclo $c) => ['valor' => $c->id, 'texto' => $c->nombre]),
            'cicloId' => $ciclo?->id,
            'panorama' => $ciclo === null ? [] : $this->presupuesto->panorama($ciclo->id),
            'centros' => CentroCosto::query()->with('campus:id,nombre')->orderBy('nombre')->get()
                ->map(fn (CentroCosto $c) => [
                    'id' => $c->id,
                    'clave' => $c->clave,
                    'nombre' => $c->nombre,
                    'campus_id' => $c->campus_id,
                    'campus' => $c->campus?->nombre,
                    'notas' => $c->notas,
                    'activo' => $c->activo,
                ]),
            'partidas' => PartidaPresupuesto::query()->orderBy('nombre')->get(['id', 'clave', 'nombre', 'notas', 'activo']),
            'campus' => Campus::query()->orderBy('nombre')->get(['id', 'nombre'])
                ->map(fn (Campus $c) => ['valor' => $c->id, 'texto' => $c->nombre]),
            'puedeGestionar' => $peticion->user()->can('gestionar-presupuesto'),
            // Los periodos de nómina cerrados que todavía no se han traído: es
            // el gasto más grande de la escuela y no se debe quedar fuera.
            'nominasPendientes' => $this->presupuesto->nominasPendientes()
                ->map(fn (PeriodoNomina $p) => [
                    'id' => $p->id,
                    'nombre' => $p->nombre,
                    'fin' => $p->fecha_fin?->toDateString(),
                    'neto' => round((float) $p->recibos()->sum('neto'), 2),
                ]),
        ]);
    }

    public function guardarCentro(Request $peticion, ?CentroCosto $centro = null): RedirectResponse
    {
        $datos = $peticion->validate([
            'clave' => ['required', 'string', 'max:30', Rule::unique('centros_costo', 'clave')->ignore($centro?->id)->whereNull('deleted_at')],
            'nombre' => ['required', 'string', 'max:120'],
            // Nullable a propósito: hay gasto que no es de ningún plantel.
            'campus_id' => ['nullable', 'integer', Rule::exists('campus', 'id')],
            'notas' => ['nullable', 'string', 'max:255'],
            'activo' => ['required', 'boolean'],
        ]);

        $datos['activo'] = $peticion->boolean('activo');

        $centro === null
            ? CentroCosto::create($datos)
            : $centro->update($datos);

        return back(303)->with('exito', 'Centro de costo guardado.');
    }

    public function guardarPartida(Request $peticion, ?PartidaPresupuesto $partida = null): RedirectResponse
    {
        $datos = $peticion->validate([
            'clave' => ['required', 'string', 'max:30', Rule::unique('partidas_presupuesto', 'clave')->ignore($partida?->id)->whereNull('deleted_at')],
            'nombre' => ['required', 'string', 'max:120'],
            'notas' => ['nullable', 'string', 'max:255'],
            'activo' => ['required', 'boolean'],
        ]);

        $datos['activo'] = $peticion->boolean('activo');

        $partida === null
            ? PartidaPresupuesto::create($datos)
            : $partida->update($datos);

        return back(303)->with('exito', 'Partida guardada.');
    }

    public function guardarPresupuesto(Request $peticion): RedirectResponse
    {
        $datos = $peticion->validate([
            'centro_costo_id' => ['required', 'integer', Rule::exists('centros_costo', 'id')],
            'partida_id' => ['required', 'integer', Rule::exists('partidas_presupuesto', 'id')],
            'ciclo_id' => ['required', 'integer', Rule::exists('ciclos', 'id')],
            'monto' => ['required', 'numeric', 'min:0'],
            'notas' => ['nullable', 'string', 'max:255'],
        ]);

        /*
         * `updateOrCreate` y no `create`: la cifra de un cruce se corrige, y con
         * un alta a secas la segunda vez chocaría contra el único con un error
         * de base en la cara de quien captura.
         */
        Presupuesto::updateOrCreate(
            [
                'centro_costo_id' => (int) $datos['centro_costo_id'],
                'partida_id' => (int) $datos['partida_id'],
                'ciclo_id' => (int) $datos['ciclo_id'],
            ],
            ['monto' => (float) $datos['monto'], 'notas' => $datos['notas'] ?? null],
        );

        return back(303)->with('exito', 'Presupuesto guardado.');
    }

    public function traerNomina(Request $peticion): RedirectResponse
    {
        $datos = $peticion->validate([
            'periodo_id' => ['required', 'integer', Rule::exists('periodos_nomina', 'id')],
            'partida_id' => ['required', 'integer', Rule::exists('partidas_presupuesto', 'id')],
            'ciclo_id' => ['required', 'integer', Rule::exists('ciclos', 'id')],
        ]);

        $r = $this->presupuesto->traerNomina(
            PeriodoNomina::findOrFail($datos['periodo_id']),
            PartidaPresupuesto::findOrFail($datos['partida_id']),
            (int) $datos['ciclo_id'],
        );

        return back(303)->with(
            'exito',
            'Nómina traída al presupuesto: $'.number_format($r['neto'], 2).' como egreso #'.$r['egreso']->id.'.'
        );
    }

    private function cicloElegido(Request $peticion): ?Ciclo
    {
        $pedido = (int) $peticion->query('ciclo', 0);

        return $pedido > 0
            ? Ciclo::find($pedido) ?? Ciclo::query()->orderByDesc('id')->first()
            : Ciclo::query()->orderByDesc('id')->first();
    }

    /** Cuántos egresos cuelgan de un cruce: lo que decide si se puede apagar. */
    public function apagarCentro(CentroCosto $centro): RedirectResponse
    {
        $usados = Egreso::query()->where('centro_costo_id', $centro->id)->count();

        if ($usados > 0) {
            // No se borra: sus egresos lo nombran y el panorama quedaría
            // diciendo «Ya no existe» sobre gasto real. Se apaga.
            $centro->update(['activo' => false]);

            return back(303)->with('exito', "Centro apagado. Sus {$usados} egreso(s) se conservan.");
        }

        $centro->delete();

        return back(303)->with('exito', 'Centro de costo retirado.');
    }
}
