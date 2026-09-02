<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\AvisoParaElUsuario;
use App\Models\ControlEscolar\Ciclo;
use App\Models\Finanzas\CentroCosto;
use App\Models\Finanzas\Egreso;
use App\Models\Finanzas\PartidaPresupuesto;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * El registro de egresos: el dinero que sale.
 *
 * ── Esto NO es contabilidad ────────────────────────────────────────────────
 * No hay órdenes de compra ni cuentas por pagar, y el comprobante que se
 * adjunta no se valida ni se timbra. Es control presupuestal: contra qué
 * partida se cargó cada salida. Media implementación de cuentas por pagar sería
 * peor que ninguna, porque se usaría como si fuera contabilidad.
 *
 * ── Se corrige, y por eso importa la auditoría ─────────────────────────────
 * Un egreso es la CAPTURA de algo que pasó en otro lado, no un documento que la
 * escuela emite: los errores de captura son la norma. Lo que le da autoridad al
 * renglón es su comprobante y el rastro de quién lo escribió y quién lo cambió,
 * no que no se pueda tocar.
 */
class EgresoController extends Controller
{
    public function index(Request $peticion): Response
    {
        $ciclo = (int) $peticion->query('ciclo', 0)
            ?: (int) (Ciclo::query()->orderByDesc('id')->value('id') ?? 0);

        $centro = (int) $peticion->query('centro', 0);
        $partida = (int) $peticion->query('partida', 0);

        $consulta = Egreso::query()
            ->with(['centro:id,nombre', 'partida:id,nombre'])
            ->when($ciclo > 0, fn ($q) => $q->where('ciclo_id', $ciclo))
            ->when($centro > 0, fn ($q) => $q->where('centro_costo_id', $centro))
            ->when($partida > 0, fn ($q) => $q->where('partida_id', $partida));

        $egresos = (clone $consulta)->orderByDesc('fecha')->orderByDesc('id')->limit(300)->get();

        return Inertia::render('Finanzas/Egresos/Index', [
            'egresos' => $egresos->map(fn (Egreso $e) => [
                'id' => $e->id,
                'fecha' => $e->fecha?->toDateString(),
                'centro' => $e->centro?->nombre,
                'partida' => $e->partida?->nombre,
                'monto' => (float) $e->monto,
                'descripcion' => $e->descripcion,
                'beneficiario' => $e->beneficiario,
                'referencia' => $e->referencia,
                'comprobante' => $e->comprobante_nombre,
                'de_nomina' => $e->vieneDeNomina(),
            ]),
            // El total de lo FILTRADO, no el de la página: quien filtra por una
            // partida quiere saber cuánto suma esa partida, no los primeros 300
            // renglones de ella.
            'total' => round((float) (clone $consulta)->sum('monto'), 2),
            'filtros' => ['ciclo' => $ciclo, 'centro' => $centro, 'partida' => $partida],
            'ciclos' => Ciclo::query()->orderByDesc('id')->get(['id', 'nombre'])
                ->map(fn (Ciclo $c) => ['valor' => $c->id, 'texto' => $c->nombre]),
            'centros' => CentroCosto::query()->activos()->orderBy('nombre')->get(['id', 'nombre'])
                ->map(fn (CentroCosto $c) => ['valor' => $c->id, 'texto' => $c->nombre]),
            'partidas' => PartidaPresupuesto::query()->activas()->orderBy('nombre')->get(['id', 'nombre'])
                ->map(fn (PartidaPresupuesto $p) => ['valor' => $p->id, 'texto' => $p->nombre]),
        ]);
    }

    public function guardar(Request $peticion, ?Egreso $egreso = null): RedirectResponse
    {
        $datos = $peticion->validate([
            'fecha' => ['required', 'date'],
            'centro_costo_id' => ['required', 'integer', Rule::exists('centros_costo', 'id')],
            'partida_id' => ['required', 'integer', Rule::exists('partidas_presupuesto', 'id')],
            'ciclo_id' => ['required', 'integer', Rule::exists('ciclos', 'id')],
            'monto' => ['required', 'numeric', 'min:0.01'],
            'descripcion' => ['required', 'string', 'max:255'],
            'beneficiario' => ['nullable', 'string', 'max:160'],
            'referencia' => ['nullable', 'string', 'max:100'],
            'comprobante' => ['nullable', 'file', 'mimes:pdf,xml,jpg,jpeg,png', 'max:8192'],
        ], [
            'descripcion.required' => 'Escribe en qué se gastó: un renglón sin concepto engorda el total y no explica nada.',
        ]);

        /*
         * Un egreso traído de la nómina no se edita a mano. Su importe es el
         * neto de un periodo cerrado, y cambiarlo aquí dejaría el presupuesto
         * diciendo una cosa y la nómina otra, sin que nada las contradijera.
         */
        AvisoParaElUsuario::si(
            $egreso?->vieneDeNomina() === true,
            422,
            'Ese egreso viene de un periodo de nómina: su importe es el neto de ese periodo y no se corrige aquí.',
        );

        $archivo = $peticion->file('comprobante');

        if ($archivo !== null) {
            // Al disco privado: una factura de proveedor trae datos fiscales de
            // terceros.
            $datos['comprobante_ruta'] = $archivo->store('egresos/comprobantes', 'local');
            $datos['comprobante_nombre'] = $archivo->getClientOriginalName();
        }

        unset($datos['comprobante']);

        $egreso === null
            ? Egreso::create($datos)
            : $egreso->update($datos);

        return back(303)->with('exito', 'Egreso guardado.');
    }

    public function descargar(Egreso $egreso): StreamedResponse
    {
        abort_unless($egreso->comprobante_ruta !== null, 404);
        abort_unless(Storage::disk('local')->exists($egreso->comprobante_ruta), 404);

        return Storage::disk('local')->download(
            $egreso->comprobante_ruta,
            $egreso->comprobante_nombre ?? 'comprobante',
        );
    }

    /**
     * Retira un egreso capturado por error.
     *
     * Baja lógica: el rastro de que alguien lo capturó y lo retiró es parte de
     * lo que hace confiable el total. Y los de NÓMINA no se retiran desde aquí
     * —volver a traer el periodo chocaría contra su único y el presupuesto se
     * quedaría sin el gasto más grande del mes sin que nada lo dijera—.
     */
    public function eliminar(Egreso $egreso): RedirectResponse
    {
        AvisoParaElUsuario::si(
            $egreso->vieneDeNomina(),
            422,
            'Ese egreso viene de un periodo de nómina. Si sobra, revisa el periodo: quitarlo de aquí dejaría el presupuesto sin el gasto más grande del mes.',
        );

        $egreso->delete();

        return back(303)->with('exito', 'Egreso retirado.');
    }
}
