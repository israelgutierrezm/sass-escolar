<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\AvisoParaElUsuario;
use App\Models\Finanzas\ConciliacionPartida;
use App\Models\Finanzas\CuentaBancaria;
use App\Models\Finanzas\EstadoCuentaBancaria;
use App\Models\Finanzas\MovimientoBancario;
use App\Services\Banco\ConciliadorBancario;
use App\Services\Banco\ImportadorEstadoCuenta;
use App\Services\Banco\MapeoEstadoCuenta;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Conciliación bancaria: cuadrar el banco contra el sistema.
 *
 * ── No se acota por campus, a propósito ────────────────────────────────────
 * Una cuenta bancaria es de la persona moral, no de un plantel: «conciliar
 * marzo del campus norte» no significa nada frente al banco. Mismo criterio
 * que el cierre fiscal.
 *
 * ── Y no escribe en los pagos ──────────────────────────────────────────────
 * Lo único que esta pantalla guarda son vínculos y clasificaciones. El insumo
 * es un CSV que cualquiera edita en su máquina; si de él dependiera el estatus
 * de un cobro, un archivo retocado movería lo que un alumno debe.
 */
class ConciliacionBancariaController extends Controller
{
    public function __construct(
        private readonly ImportadorEstadoCuenta $importador,
        private readonly ConciliadorBancario $conciliador,
    ) {}

    public function index(): Response
    {
        return Inertia::render('Finanzas/Conciliacion/Index', [
            'cuentas' => CuentaBancaria::query()
                ->orderBy('nombre')
                ->get(['id', 'nombre', 'banco', 'clabe', 'activa', 'mapeo_estado_cuenta'])
                ->map(fn (CuentaBancaria $c) => [
                    'id' => $c->id,
                    'nombre' => $c->nombre,
                    'banco' => $c->banco,
                    'clabe' => $c->clabe,
                    'activa' => $c->activa,
                    // Sin mapeo no se puede importar nada, así que la pantalla
                    // tiene que decirlo antes de que alguien suba un archivo.
                    'tiene_mapeo' => $c->mapeo_estado_cuenta !== null,
                    'mapeo' => MapeoEstadoCuenta::desde($c->mapeo_estado_cuenta)->comoArreglo(),
                ]),
            'estados' => EstadoCuentaBancaria::query()
                ->with('cuenta:id,nombre,banco')
                ->orderByDesc('periodo_inicio')
                ->limit(50)
                ->get()
                ->map(fn (EstadoCuentaBancaria $e) => $this->resumen($e)),
            'mapeoPorOmision' => MapeoEstadoCuenta::porOmision(),
        ]);
    }

    public function guardarMapeo(Request $peticion, CuentaBancaria $cuenta): RedirectResponse
    {
        $datos = $peticion->validate([
            'delimitador' => ['required', 'string', 'max:8'],
            'renglon_encabezado' => ['required', 'integer', 'min:1', 'max:50'],
            'formato_fecha' => ['required', 'string', 'max:20'],
            'columna_fecha' => ['required', 'string', 'max:80'],
            'columna_descripcion' => ['required', 'string', 'max:80'],
            'columna_referencia' => ['nullable', 'string', 'max:80'],
            'columna_monto' => ['nullable', 'string', 'max:80'],
            'columna_cargo' => ['nullable', 'string', 'max:80'],
            'columna_abono' => ['nullable', 'string', 'max:80'],
        ]);

        $datos['renglon_encabezado'] = (int) $datos['renglon_encabezado'];

        // Se valida ANTES de guardar: un mapeo incoherente no revienta al
        // guardarse, revienta meses después en mitad de una importación y quien
        // lo capturó ya no está.
        MapeoEstadoCuenta::validar($datos);

        $cuenta->update(['mapeo_estado_cuenta' => $datos]);

        return back(303)->with('exito', 'Mapeo guardado.');
    }

    public function importar(Request $peticion): RedirectResponse
    {
        $datos = $peticion->validate([
            'cuenta_bancaria_id' => ['required', 'integer', Rule::exists('cuentas_bancarias', 'id')],
            'periodo_inicio' => ['required', 'date'],
            'periodo_fin' => ['required', 'date', 'after_or_equal:periodo_inicio'],
            'saldo_inicial' => ['required', 'numeric'],
            'saldo_final' => ['required', 'numeric'],
            'archivo' => ['required', 'file', 'mimes:csv,txt', 'max:8192'],
        ], [
            'archivo.mimes' => 'El estado de cuenta se importa en CSV. Es el formato que todos los bancos ofrecen y el único que se puede leer sin reglas por banco.',
        ]);

        $cuenta = CuentaBancaria::findOrFail($datos['cuenta_bancaria_id']);

        AvisoParaElUsuario::aMenosQue(
            $cuenta->mapeo_estado_cuenta !== null,
            422,
            'Esa cuenta todavía no dice cómo se lee su archivo. Configura su mapeo de columnas antes de importar.',
        );

        // Se guarda tal como llegó, en el disco privado: trae nombres y
        // referencias de quien paga, y es la evidencia de lo importado.
        $ruta = $peticion->file('archivo')->store('banco/estados-cuenta/'.$cuenta->id, 'local');

        $r = $this->importador->importar(
            $cuenta,
            Storage::disk('local')->path($ruta),
            $datos['periodo_inicio'],
            $datos['periodo_fin'],
            (float) $datos['saldo_inicial'],
            (float) $datos['saldo_final'],
            $ruta,
            $peticion->file('archivo')->getClientOriginalName(),
        );

        return redirect("/finanzas/conciliacion/{$r['estado']->id}")->with(
            'exito',
            "Estado de cuenta importado: {$r['nuevos']} movimiento(s)"
            .($r['repetidos'] > 0 ? ", {$r['repetidos']} que ya estaban" : '').'.'
        );
    }

    public function detalle(EstadoCuentaBancaria $estado): Response
    {
        $estado->load('cuenta:id,nombre,banco,clabe');

        $movimientos = MovimientoBancario::query()
            ->where('estado_cuenta_id', $estado->id)
            ->with(['partidas.pago:id,referencia,monto', 'partidas.deposito:id,referencia,monto'])
            ->orderBy('fecha')
            ->orderBy('id')
            ->get();

        $panorama = $this->conciliador->panorama($estado);

        return Inertia::render('Finanzas/Conciliacion/Detalle', [
            'estado' => $this->resumen($estado),
            'movimientos' => $movimientos->map(fn (MovimientoBancario $m) => [
                'id' => $m->id,
                'fecha' => $m->fecha?->toDateString(),
                'descripcion' => $m->descripcion,
                'referencia' => $m->referencia,
                'monto' => (float) $m->monto,
                'entrada' => $m->esEntrada(),
                'conciliado' => $m->conciliado(),
                'pendiente' => $m->pendiente(),
                'resuelto' => $m->estaResuelto(),
                'clasificacion' => $m->clasificacion,
                'nota' => $m->nota,
                'partidas' => $m->partidas->map(fn (ConciliacionPartida $p) => [
                    'id' => $p->id,
                    'que' => $p->descripcion(),
                    'monto' => (float) $p->monto_aplicado,
                    'automatica' => $p->automatica,
                ])->values(),
            ]),
            'clasificaciones' => collect(MovimientoBancario::clasificaciones())
                ->map(fn ($texto, $clave) => ['valor' => $clave, 'texto' => $texto])
                ->values(),
            'sinLlegar' => $panorama['sin_llegar'],
            'totales' => [
                'sin_registrar' => $panorama['total_sin_registrar'],
                'sin_llegar' => $panorama['total_sin_llegar'],
            ],
        ]);
    }

    public function candidatos(MovimientoBancario $movimiento): JsonResponse
    {
        return response()->json(['candidatos' => $this->conciliador->candidatos($movimiento)]);
    }

    public function conciliar(Request $peticion, MovimientoBancario $movimiento): RedirectResponse
    {
        $datos = $peticion->validate([
            'claves' => ['required', 'array', 'min:1'],
            'claves.*' => ['required', 'string', 'max:40'],
        ]);

        $n = $this->conciliador->conciliar($movimiento, $datos['claves']);

        return back(303)->with('exito', "Se ataron {$n} movimiento(s) a este renglón.");
    }

    public function clasificar(Request $peticion, MovimientoBancario $movimiento): RedirectResponse
    {
        $datos = $peticion->validate([
            'clasificacion' => ['nullable', 'string', 'max:30'],
            'nota' => ['nullable', 'string', 'max:255'],
        ]);

        $this->conciliador->clasificar($movimiento, $datos['clasificacion'] ?? null, $datos['nota'] ?? null);

        return back(303)->with('exito', 'Renglón clasificado.');
    }

    public function desconciliar(ConciliacionPartida $partida): RedirectResponse
    {
        $this->conciliador->desconciliar($partida);

        return back(303)->with('exito', 'Pareo deshecho.');
    }

    public function automatico(EstadoCuentaBancaria $estado): RedirectResponse
    {
        $r = $this->conciliador->conciliarAutomatico($estado);

        return back(303)->with(
            'exito',
            "Se casaron {$r['casados']} renglón(es) sin ninguna duda."
            .($r['ambiguos'] > 0
                ? " Otros {$r['ambiguos']} tienen más de un candidato posible y los tienes que decidir tú."
                : '')
        );
    }

    /**
     * Retira un estado de cuenta importado por error.
     *
     * Sólo mientras nadie haya conciliado nada suyo: borrarlo después se
     * llevaría por delante el trabajo de casar renglones, en silencio y sin
     * forma de recuperarlo.
     */
    public function eliminar(EstadoCuentaBancaria $estado): RedirectResponse
    {
        $conciliados = ConciliacionPartida::query()
            ->whereIn(
                'movimiento_bancario_id',
                MovimientoBancario::query()->where('estado_cuenta_id', $estado->id)->select('id'),
            )
            ->count();

        AvisoParaElUsuario::si(
            $conciliados > 0,
            422,
            "Este estado de cuenta ya tiene {$conciliados} renglón(es) conciliados. Deshaz esos pareos antes de retirarlo.",
        );

        MovimientoBancario::query()->where('estado_cuenta_id', $estado->id)->forceDelete();
        $estado->forceDelete();

        return redirect('/finanzas/conciliacion')->with('exito', 'Estado de cuenta retirado.');
    }

    /** @return array<string, mixed> */
    private function resumen(EstadoCuentaBancaria $estado): array
    {
        $sinResolver = MovimientoBancario::query()
            ->where('estado_cuenta_id', $estado->id)
            ->entradas()
            ->sinResolver()
            ->count();

        return [
            'id' => $estado->id,
            'cuenta' => $estado->cuenta?->nombre,
            'banco' => $estado->cuenta?->banco,
            'periodo_inicio' => $estado->periodo_inicio?->toDateString(),
            'periodo_fin' => $estado->periodo_fin?->toDateString(),
            'saldo_inicial' => (float) $estado->saldo_inicial,
            'saldo_final' => (float) $estado->saldo_final,
            'neto' => $estado->neto(),
            'descuadre' => $estado->descuadre(),
            'cuadra' => $estado->cuadra(),
            'movimientos' => $estado->movimientos,
            'sin_resolver' => $sinResolver,
            'archivo' => $estado->archivo_nombre,
        ];
    }
}
