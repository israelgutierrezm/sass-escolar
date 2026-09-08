<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\AvisoParaElUsuario;
use App\Models\ControlEscolar\Ciclo;
use App\Models\Finanzas\CentroCosto;
use App\Models\Finanzas\CuentaPorPagar;
use App\Models\Finanzas\Egreso;
use App\Models\Finanzas\PartidaPresupuesto;
use App\Models\Finanzas\Proveedor;
use App\Services\Finanzas\RegistradorPagoProveedor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Compras y CxP, rebanada 2: las cuentas por pagar.
 * Ver `docs/plan-compras-cxp.md`.
 *
 * Registrar la obligación y pagarla son dos oficios: `gestionar-cuentas-pagar`
 * captura y corrige la deuda; `pagar-proveedores` la paga (lo que registra un
 * egreso). El estado y el saldo se DERIVAN de los egresos, no se teclean.
 */
class CuentaPorPagarController extends Controller
{
    public function __construct(private readonly RegistradorPagoProveedor $registrador) {}

    public function index(Request $peticion): Response
    {
        $estado = (string) $peticion->query('estado', '');
        $proveedor = (int) $peticion->query('proveedor', 0);

        $consulta = CuentaPorPagar::query()
            ->with(['proveedor:id,nombre', 'centro:id,nombre', 'partida:id,nombre'])
            ->withSum('pagos as pagado', 'monto')
            ->when($estado !== '', fn ($q) => $q->where('estado', $estado))
            ->when($proveedor > 0, fn ($q) => $q->where('proveedor_id', $proveedor))
            ->orderByRaw("FIELD(estado, 'pendiente', 'parcial', 'pagada', 'cancelada')")
            ->orderBy('vencimiento')
            ->limit(500)
            ->get();

        return Inertia::render('Finanzas/CuentasPorPagar', [
            'cuentas' => $consulta->map(fn (CuentaPorPagar $c) => $this->comoArray($c)),
            'antiguedad' => $this->antiguedad(),
            // Registrar la obligación y pagarla son dos oficios: la pantalla
            // muestra los botones de cada uno según el permiso, y el servidor lo
            // vuelve a exigir.
            'permisos' => [
                'gestionar' => (bool) $peticion->user()?->can('gestionar-cuentas-pagar'),
                'pagar' => (bool) $peticion->user()?->can('pagar-proveedores'),
            ],
            'filtros' => ['estado' => $estado, 'proveedor' => $proveedor],
            'estados' => CuentaPorPagar::ETIQUETAS,
            'proveedores' => Proveedor::query()->activos()->orderBy('nombre')->get(['id', 'nombre'])
                ->map(fn (Proveedor $p) => ['valor' => $p->id, 'texto' => $p->nombre]),
            'centros' => CentroCosto::query()->activos()->orderBy('nombre')->get(['id', 'nombre'])
                ->map(fn (CentroCosto $c) => ['valor' => $c->id, 'texto' => $c->nombre]),
            'partidas' => PartidaPresupuesto::query()->activas()->orderBy('nombre')->get(['id', 'nombre'])
                ->map(fn (PartidaPresupuesto $p) => ['valor' => $p->id, 'texto' => $p->nombre]),
            'ciclos' => Ciclo::query()->orderByDesc('id')->get(['id', 'nombre'])
                ->map(fn (Ciclo $c) => ['valor' => $c->id, 'texto' => $c->nombre]),
        ]);
    }

    public function guardar(Request $peticion, ?CuentaPorPagar $cuenta = null): RedirectResponse
    {
        // Una CxP con pagos no se edita: cambiar el monto dejaría el saldo
        // diciendo una cosa y los egresos otra. Para corregir, se revierte el
        // pago primero.
        AvisoParaElUsuario::si($cuenta !== null && $cuenta->montoPagado() > 0, 422, 'Esta cuenta ya tiene pagos: revierte el pago antes de editarla.');
        AvisoParaElUsuario::si($cuenta?->estado === CuentaPorPagar::CANCELADA, 422, 'Una cuenta cancelada no se edita.');

        $datos = $peticion->validate([
            'proveedor_id' => ['required', 'integer', Rule::exists('proveedores', 'id')],
            'centro_costo_id' => ['required', 'integer', Rule::exists('centros_costo', 'id')],
            'partida_id' => ['required', 'integer', Rule::exists('partidas_presupuesto', 'id')],
            'ciclo_id' => ['required', 'integer', Rule::exists('ciclos', 'id')],
            'concepto' => ['required', 'string', 'max:255'],
            'monto' => ['required', 'numeric', 'min:0.01'],
            'fecha' => ['required', 'date'],
            'vencimiento' => ['required', 'date', 'after_or_equal:fecha'],
            'referencia' => ['nullable', 'string', 'max:100'],
        ]);

        $cuenta
            ? $cuenta->update($datos)
            : CuentaPorPagar::create($datos);

        return back(303)->with('exito', $cuenta ? 'Cuenta actualizada.' : 'Cuenta por pagar registrada.');
    }

    public function cancelar(CuentaPorPagar $cuenta): RedirectResponse
    {
        AvisoParaElUsuario::si($cuenta->montoPagado() > 0, 422, 'Esta cuenta ya tiene pagos: revierte el pago antes de cancelarla.');
        AvisoParaElUsuario::aMenosQue($cuenta->estaAbierta(), 422, 'Esta cuenta no está abierta.');

        $cuenta->update(['estado' => CuentaPorPagar::CANCELADA]);

        return back(303)->with('exito', 'Cuenta cancelada.');
    }

    public function pagar(Request $peticion, CuentaPorPagar $cuenta): RedirectResponse
    {
        $datos = $peticion->validate([
            'monto' => ['required', 'numeric', 'min:0.01'],
            'fecha' => ['required', 'date'],
            'referencia' => ['nullable', 'string', 'max:100'],
        ]);

        $this->registrador->pagar($cuenta, $datos);

        return back(303)->with('exito', 'Pago registrado (se asentó como egreso).');
    }

    public function revertir(Egreso $egreso): RedirectResponse
    {
        $this->registrador->revertirPago($egreso);

        return back(303)->with('exito', 'Pago revertido.');
    }

    /** @return array<string, mixed> */
    private function comoArray(CuentaPorPagar $c): array
    {
        $pagado = round((float) ($c->pagado ?? 0), 2);
        $saldo = round((float) $c->monto - $pagado, 2);

        return [
            'id' => $c->id,
            'proveedor' => $c->proveedor?->nombre,
            'centro' => $c->centro?->nombre,
            'partida' => $c->partida?->nombre,
            'concepto' => $c->concepto,
            'monto' => (float) $c->monto,
            'pagado' => $pagado,
            'saldo' => $saldo,
            'fecha' => $c->fecha?->toDateString(),
            'vencimiento' => $c->vencimiento?->toDateString(),
            'estado' => $c->estado,
            'vencida' => $c->estaVencida(),
            'referencia' => $c->referencia,
            'pagos' => $c->pagos()->orderBy('fecha')->get(['id', 'fecha', 'monto', 'referencia'])
                ->map(fn (Egreso $e) => ['id' => $e->id, 'fecha' => $e->fecha?->toDateString(), 'monto' => (float) $e->monto, 'referencia' => $e->referencia]),
        ];
    }

    /**
     * Antigüedad de saldos: cuánto se debe, repartido por vencimiento. Lo VENCIDO
     * va aparte —es lo que el módulo viene a contestar—.
     *
     * @return array<string, float>
     */
    private function antiguedad(): array
    {
        $hoy = now()->startOfDay();
        $buckets = ['por_vencer' => 0.0, 'vencido_1_30' => 0.0, 'vencido_31_60' => 0.0, 'vencido_60_mas' => 0.0];

        CuentaPorPagar::query()->abiertas()->withSum('pagos as pagado', 'monto')
            ->get(['id', 'monto', 'vencimiento'])
            ->each(function (CuentaPorPagar $c) use (&$buckets, $hoy): void {
                $saldo = round((float) $c->monto - (float) ($c->pagado ?? 0), 2);
                if ($saldo <= 0) {
                    return;
                }
                $dias = $c->vencimiento !== null ? $hoy->diffInDays($c->vencimiento, false) : 0;
                $clave = match (true) {
                    $dias >= 0 => 'por_vencer',
                    $dias >= -30 => 'vencido_1_30',
                    $dias >= -60 => 'vencido_31_60',
                    default => 'vencido_60_mas',
                };
                $buckets[$clave] += $saldo;
            });

        return array_map(fn ($v) => round($v, 2), $buckets);
    }
}
