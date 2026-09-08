<?php

/**
 * Compras y CxP, rebanada 2: cuentas por pagar. Con rollback.
 * Ver `docs/plan-compras-cxp.md`.
 *
 * ── Qué se vigila ─────────────────────────────────────────────────────────
 *  1. **Pagar asienta un EGRESO** (origen cxp), copiando centro/partida/proveedor;
 *     el saldo y el estado se DERIVAN de esos egresos, no se teclean.
 *  2. **No se paga de más** (bajo bloqueo), ni una cuenta cerrada o cancelada.
 *  3. **Con pagos no se edita ni se cancela**: se revierte el pago primero.
 *  4. **Revertir** borra el egreso y recompone el estado.
 *  5. **El egreso de una CxP no se toca desde la pantalla de egresos.**
 *  6. **La antigüedad de saldos** separa lo vencido de lo por vencer.
 */

use App\Http\Controllers\CuentaPorPagarController;
use App\Http\Controllers\EgresoController;
use App\Models\ControlEscolar\Ciclo;
use App\Models\Finanzas\CentroCosto;
use App\Models\Finanzas\CuentaPorPagar;
use App\Models\Finanzas\Egreso;
use App\Models\Finanzas\PartidaPresupuesto;
use App\Models\Finanzas\Proveedor;
use App\Models\Identidad\Usuario;
use App\Models\Tenant;
use App\Services\Finanzas\RegistradorPagoProveedor;
use App\Support\CatalogoPermisos;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

tenancy()->initialize(Tenant::find('demo'));

$db = DB::connection('tenant');

$verificaciones = 0;
$fallidas = 0;

function verificar(string $que, bool $ok, string $detalle = ''): void
{
    global $verificaciones, $fallidas;
    $verificaciones++;
    $ok || $fallidas++;
    echo ($ok ? "  \033[32mOK\033[39m   " : "  \033[31mFALLA\033[39m ").$que.($detalle !== '' ? "  [{$detalle}]" : '').PHP_EOL;
}

function fallo(callable $accion): ?int
{
    try {
        $accion();
    } catch (HttpException $e) {
        return $e->getStatusCode();
    } catch (ValidationException) {
        return 422;
    }

    return null;
}

function peticionDe(Usuario $usuario, array $datos = []): Request
{
    $p = Request::create('/', 'POST', $datos);
    $p->setUserResolver(fn () => $usuario);

    return $p;
}

const PREF = 'ZZCXP-';

$db->beginTransaction();

try {
    $admin = Usuario::query()->where('usuario', 'demo')->firstOrFail();
    $ctrl = app(CuentaPorPagarController::class);
    $registrador = app(RegistradorPagoProveedor::class);

    $prov = Proveedor::create(['nombre' => PREF.'Proveedor', 'activo' => true]);
    $centro = CentroCosto::create(['clave' => PREF.'C', 'nombre' => PREF.'Centro', 'activo' => true]);
    $partida = PartidaPresupuesto::create(['clave' => PREF.'P', 'nombre' => PREF.'Partida', 'activo' => true]);
    $ciclo = (int) Ciclo::query()->orderByDesc('id')->value('id');

    $base = fn (array $extra = []) => array_merge([
        'proveedor_id' => $prov->id, 'centro_costo_id' => $centro->id, 'partida_id' => $partida->id,
        'ciclo_id' => $ciclo, 'concepto' => PREF.'servicio', 'monto' => 1000,
        'fecha' => now()->toDateString(), 'vencimiento' => now()->addDays(10)->toDateString(),
    ], $extra);

    // ── 1. Alta y pago; el estado se deriva ─────────────────────────────────
    echo PHP_EOL.'1. Pagar asienta un egreso; el estado se deriva'.PHP_EOL;

    $ctrl->guardar(peticionDe($admin, $base()));
    $cxp = CuentaPorPagar::query()->where('concepto', PREF.'servicio')->latest('id')->firstOrFail();
    verificar('Nace pendiente', $cxp->estado === CuentaPorPagar::PENDIENTE && $cxp->saldo() === 1000.0);

    $egresosAntes = Egreso::query()->count();
    $registrador->pagar($cxp, ['monto' => 400, 'fecha' => now()->toDateString(), 'referencia' => PREF.'tr1']);
    $cxp->refresh();
    verificar('Pago parcial → estado parcial, saldo 600', $cxp->estado === CuentaPorPagar::PARCIAL && $cxp->saldo() === 600.0);
    verificar('El pago asentó UN egreso', Egreso::query()->count() === $egresosAntes + 1);

    $egresoPago = Egreso::query()->where('cuenta_por_pagar_id', $cxp->id)->latest('id')->first();
    verificar('El egreso copia proveedor, centro y partida y su monto',
        (int) $egresoPago->proveedor_id === $prov->id && (int) $egresoPago->centro_costo_id === $centro->id
        && (int) $egresoPago->partida_id === $partida->id && (float) $egresoPago->monto === 400.0);

    $registrador->pagar($cxp, ['monto' => 600, 'fecha' => now()->toDateString()]);
    $cxp->refresh();
    verificar('Pagada por completo → estado pagada, saldo 0', $cxp->estado === CuentaPorPagar::PAGADA && $cxp->saldo() === 0.0);
    verificar('Una cuenta pagada ya no admite pagos → 422',
        fallo(fn () => $registrador->pagar($cxp, ['monto' => 1, 'fecha' => now()->toDateString()])) === 422);

    // ── 2. No se paga de más ────────────────────────────────────────────────
    echo PHP_EOL.'2. No se paga de más, ni cero'.PHP_EOL;

    $ctrl->guardar(peticionDe($admin, $base(['concepto' => PREF.'otra'])));
    $cxp2 = CuentaPorPagar::query()->where('concepto', PREF.'otra')->latest('id')->firstOrFail();
    verificar('Pagar más que el saldo → 422', fallo(fn () => $registrador->pagar($cxp2, ['monto' => 1500, 'fecha' => now()->toDateString()])) === 422);
    verificar('Pagar cero → 422', fallo(fn () => $registrador->pagar($cxp2, ['monto' => 0, 'fecha' => now()->toDateString()])) === 422);

    // El pago bloquea la CxP (for update). Se mide el SQL emitido.
    $vioLock = false;
    DB::listen(function ($q) use (&$vioLock) {
        if (stripos($q->sql, 'for update') !== false && stripos($q->sql, 'cuentas_por_pagar') !== false) {
            $vioLock = true;
        }
    });
    $registrador->pagar($cxp2, ['monto' => 100, 'fecha' => now()->toDateString()]);
    verificar('El pago bloquea la CxP (SELECT ... FOR UPDATE)', $vioLock);

    // ── 3. Con pagos no se edita ni se cancela ──────────────────────────────
    echo PHP_EOL.'3. Con pagos, ni editar ni cancelar'.PHP_EOL;

    // cxp2 ya tiene un pago.
    verificar('Editar una cuenta con pagos → 422',
        fallo(fn () => $ctrl->guardar(peticionDe($admin, $base(['concepto' => PREF.'editada'])), $cxp2)) === 422);
    verificar('Cancelar una cuenta con pagos → 422',
        fallo(fn () => $ctrl->cancelar($cxp2)) === 422);

    // Una sin pagos: se edita y se cancela.
    $ctrl->guardar(peticionDe($admin, $base(['concepto' => PREF.'limpia'])));
    $cxp3 = CuentaPorPagar::query()->where('concepto', PREF.'limpia')->latest('id')->firstOrFail();
    $ctrl->guardar(peticionDe($admin, $base(['concepto' => PREF.'limpia2', 'monto' => 2000])), $cxp3);
    verificar('Sin pagos sí se edita', $cxp3->fresh()->concepto === PREF.'limpia2' && (float) $cxp3->fresh()->monto === 2000.0);
    $ctrl->cancelar($cxp3);
    verificar('Sin pagos sí se cancela', $cxp3->fresh()->estado === CuentaPorPagar::CANCELADA);
    verificar('Una cancelada no admite pagos → 422',
        fallo(fn () => $registrador->pagar($cxp3->fresh(), ['monto' => 1, 'fecha' => now()->toDateString()])) === 422);

    // ── 4. Revertir ─────────────────────────────────────────────────────────
    echo PHP_EOL.'4. Revertir un pago recompone el estado'.PHP_EOL;

    $ctrl->guardar(peticionDe($admin, $base(['concepto' => PREF.'rev'])));
    $cxp4 = CuentaPorPagar::query()->where('concepto', PREF.'rev')->latest('id')->firstOrFail();
    $registrador->pagar($cxp4, ['monto' => 400, 'fecha' => now()->toDateString()]);
    $registrador->pagar($cxp4, ['monto' => 600, 'fecha' => now()->toDateString()]);
    verificar('Tras dos pagos, pagada', $cxp4->fresh()->estado === CuentaPorPagar::PAGADA);

    $ultimoPago = Egreso::query()->where('cuenta_por_pagar_id', $cxp4->id)->latest('id')->firstOrFail();
    $registrador->revertirPago($ultimoPago);
    verificar('Revertido: vuelve a parcial, saldo 600', $cxp4->fresh()->estado === CuentaPorPagar::PARCIAL && $cxp4->fresh()->saldo() === 600.0);
    verificar('El egreso del pago revertido desapareció', Egreso::query()->whereKey($ultimoPago->id)->doesntExist());

    $egresoNormal = Egreso::create(['fecha' => now()->toDateString(), 'centro_costo_id' => $centro->id, 'partida_id' => $partida->id, 'ciclo_id' => $ciclo, 'monto' => 50, 'descripcion' => PREF.'normal']);
    verificar('Revertir un egreso que NO es pago de CxP → 422',
        fallo(fn () => $registrador->revertirPago($egresoNormal)) === 422);

    // ── 5. El egreso de una CxP no se toca desde egresos ────────────────────
    echo PHP_EOL.'5. El pago no se edita ni se borra desde egresos'.PHP_EOL;

    $ctrlEgreso = app(EgresoController::class);
    $pagoCxp = Egreso::query()->where('cuenta_por_pagar_id', $cxp4->id)->latest('id')->firstOrFail();
    verificar('Editar el egreso de una CxP desde egresos → 422',
        fallo(fn () => $ctrlEgreso->guardar(peticionDe($admin, ['fecha' => now()->toDateString(), 'centro_costo_id' => $centro->id, 'partida_id' => $partida->id, 'ciclo_id' => $ciclo, 'monto' => 999, 'descripcion' => 'x']), $pagoCxp)) === 422);
    verificar('Borrar el egreso de una CxP desde egresos → 422',
        fallo(fn () => $ctrlEgreso->eliminar($pagoCxp)) === 422);

    // ── 6. Antigüedad de saldos ─────────────────────────────────────────────
    echo PHP_EOL.'6. La antigüedad separa lo vencido'.PHP_EOL;

    // Una vencida hace 40 días y una por vencer. Creadas directas para fijar
    // el vencimiento en el pasado.
    CuentaPorPagar::create(['proveedor_id' => $prov->id, 'centro_costo_id' => $centro->id, 'partida_id' => $partida->id, 'ciclo_id' => $ciclo, 'concepto' => PREF.'vieja', 'monto' => 700, 'fecha' => now()->subDays(50)->toDateString(), 'vencimiento' => now()->subDays(40)->toDateString()]);
    CuentaPorPagar::create(['proveedor_id' => $prov->id, 'centro_costo_id' => $centro->id, 'partida_id' => $partida->id, 'ciclo_id' => $ciclo, 'concepto' => PREF.'futura', 'monto' => 300, 'fecha' => now()->toDateString(), 'vencimiento' => now()->addDays(20)->toDateString()]);

    $props = json_decode(
        (function () use ($admin, $ctrl) {
            $p = Request::create('/', 'GET');
            $p->setUserResolver(fn () => $admin);
            $p->headers->set('X-Inertia', 'true');
            $p->headers->set('X-Inertia-Version', '');

            return $ctrl->index($p)->toResponse($p)->getContent();
        })(),
        true
    )['props'];
    $ant = $props['antiguedad'];
    verificar('Lo por vencer incluye la futura (300)', $ant['por_vencer'] >= 300.0);
    verificar('Lo vencido +31 días incluye la vieja (700)', ($ant['vencido_31_60'] + $ant['vencido_60_mas']) >= 700.0);

    // ── 7. Permisos ─────────────────────────────────────────────────────────
    echo PHP_EOL.'7. Permisos: dos oficios y una puerta'.PHP_EOL;

    verificar('gestionar-cuentas-pagar es administrativo', CatalogoPermisos::correspondeA('gestionar-cuentas-pagar', 'administrativo'));
    verificar('pagar-proveedores es administrativo', CatalogoPermisos::correspondeA('pagar-proveedores', 'administrativo'));
    verificar('El admin ve las cuentas por pagar (puerta derivada)', $admin->can('ver-cuentas-pagar'));
} catch (Throwable $e) {
    verificar('La suite corrió hasta el final sin morir', false,
        get_class($e).': '.$e->getMessage().' @ '.basename($e->getFile()).':'.$e->getLine());
} finally {
    $db->rollBack();
}

echo PHP_EOL.'Resultado: '.($verificaciones - $fallidas).' correctas, '.$fallidas.' fallidas'.PHP_EOL;

exit($fallidas === 0 ? 0 : 1);
