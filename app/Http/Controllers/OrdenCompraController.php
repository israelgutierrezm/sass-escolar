<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\AvisoParaElUsuario;
use App\Models\ControlEscolar\Ciclo;
use App\Models\Finanzas\CentroCosto;
use App\Models\Finanzas\OrdenCompra;
use App\Models\Finanzas\PartidaPresupuesto;
use App\Models\Finanzas\Proveedor;
use App\Services\Finanzas\GestorDeOrdenesCompra;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Compras y CxP, rebanada 3: las órdenes de compra.
 * Ver `docs/plan-compras-cxp.md`.
 *
 * Armar y recibir la OC va con `gestionar-ordenes-compra`; AUTORIZARLA (lo que la
 * vuelve un compromiso) con `autorizar-ordenes-compra` —quien pide no es quien
 * autoriza el gasto—. Recibirla genera la cuenta por pagar; no crea egreso.
 */
class OrdenCompraController extends Controller
{
    public function __construct(private readonly GestorDeOrdenesCompra $gestor) {}

    public function index(Request $peticion): Response
    {
        $estado = (string) $peticion->query('estado', '');
        $proveedor = (int) $peticion->query('proveedor', 0);

        $ordenes = OrdenCompra::query()
            ->with(['proveedor:id,nombre', 'centro:id,nombre', 'partida:id,nombre', 'conceptos', 'autorizadaPor:id,nombre,primer_apellido,segundo_apellido'])
            ->when($estado !== '', fn ($q) => $q->where('estado', $estado))
            ->when($proveedor > 0, fn ($q) => $q->where('proveedor_id', $proveedor))
            ->orderByRaw("FIELD(estado, 'borrador', 'autorizada', 'recibida', 'cerrada', 'cancelada')")
            ->orderByDesc('fecha')
            ->limit(300)
            ->get();

        return Inertia::render('Finanzas/OrdenesCompra', [
            'ordenes' => $ordenes->map(fn (OrdenCompra $o) => $this->comoArray($o)),
            'permisos' => [
                'gestionar' => (bool) $peticion->user()?->can('gestionar-ordenes-compra'),
                'autorizar' => (bool) $peticion->user()?->can('autorizar-ordenes-compra'),
            ],
            'filtros' => ['estado' => $estado, 'proveedor' => $proveedor],
            'estados' => OrdenCompra::ETIQUETAS,
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

    public function guardar(Request $peticion, ?OrdenCompra $orden = null): RedirectResponse
    {
        // Sólo un borrador se edita: una vez autorizada es un compromiso.
        AvisoParaElUsuario::si($orden !== null && ! $orden->esBorrador(), 422, 'Sólo se edita una orden en borrador.');

        $datos = $peticion->validate([
            'proveedor_id' => ['required', 'integer', Rule::exists('proveedores', 'id')],
            'centro_costo_id' => ['required', 'integer', Rule::exists('centros_costo', 'id')],
            'partida_id' => ['required', 'integer', Rule::exists('partidas_presupuesto', 'id')],
            'ciclo_id' => ['required', 'integer', Rule::exists('ciclos', 'id')],
            'fecha' => ['required', 'date'],
            'referencia' => ['nullable', 'string', 'max:100'],
            'notas' => ['nullable', 'string', 'max:500'],
            'conceptos' => ['required', 'array', 'min:1'],
            'conceptos.*.descripcion' => ['required', 'string', 'max:255'],
            'conceptos.*.cantidad' => ['required', 'numeric', 'gt:0'],
            'conceptos.*.precio_unitario' => ['required', 'numeric', 'min:0'],
        ]);

        $conceptos = $datos['conceptos'];
        unset($datos['conceptos']);

        $orden = $orden ?? new OrdenCompra;
        $orden->fill($datos)->save();

        // Los conceptos se REEMPLAZAN (sólo en borrador): la lista que llega es la
        // verdad de la orden.
        $orden->conceptos()->delete();
        foreach ($conceptos as $c) {
            $orden->conceptos()->create([
                'descripcion' => $c['descripcion'],
                'cantidad' => $c['cantidad'],
                'precio_unitario' => $c['precio_unitario'],
            ]);
        }

        return back(303)->with('exito', $orden->wasRecentlyCreated ? 'Orden de compra creada.' : 'Orden actualizada.');
    }

    public function autorizar(Request $peticion, OrdenCompra $orden): RedirectResponse
    {
        $this->gestor->autorizar($orden, (int) $peticion->user()->persona_id);

        return back(303)->with('exito', 'Orden autorizada.');
    }

    public function recibir(Request $peticion, OrdenCompra $orden): RedirectResponse
    {
        $datos = $peticion->validate([
            'monto' => ['required', 'numeric', 'gt:0'],
            'fecha' => ['required', 'date'],
            'vencimiento' => ['required', 'date', 'after_or_equal:fecha'],
            'referencia' => ['nullable', 'string', 'max:100'],
        ]);

        $this->gestor->recibir($orden, $datos);

        return back(303)->with('exito', 'Recepción registrada. Se generó la cuenta por pagar.');
    }

    public function cancelar(OrdenCompra $orden): RedirectResponse
    {
        $this->gestor->cancelar($orden);

        return back(303)->with('exito', 'Orden cancelada.');
    }

    /** @return array<string, mixed> */
    private function comoArray(OrdenCompra $o): array
    {
        return [
            'id' => $o->id,
            'proveedor' => $o->proveedor?->nombre,
            'centro' => $o->centro?->nombre,
            'partida' => $o->partida?->nombre,
            'fecha' => $o->fecha?->toDateString(),
            'estado' => $o->estado,
            'total' => $o->total(),
            'recibido' => $o->montoRecibido(),
            'por_recibir' => $o->porRecibir(),
            'referencia' => $o->referencia,
            'notas' => $o->notas,
            'autorizada_por' => $o->autorizadaPor?->nombreCompleto(),
            'conceptos' => $o->conceptos->map(fn ($c) => [
                'id' => $c->id,
                'descripcion' => $c->descripcion,
                'cantidad' => (float) $c->cantidad,
                'precio_unitario' => (float) $c->precio_unitario,
                'importe' => $c->importe(),
            ])->values(),
        ];
    }
}
