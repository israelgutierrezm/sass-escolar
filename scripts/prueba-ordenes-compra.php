<?php

/**
 * Compras y CxP, rebanada 3: órdenes de compra. Con rollback.
 * Ver `docs/plan-compras-cxp.md`.
 *
 * ── Qué se vigila ─────────────────────────────────────────────────────────
 *  1. **El total sale de los conceptos.** Sólo un borrador se edita.
 *  2. **Autorizar** exige conceptos y total > 0; sólo un borrador.
 *  3. **Recibir genera la CxP** por lo recibido (origen orden_compra), avanza a
 *     recibida/cerrada, y no se recibe de más (bajo bloqueo).
 *  4. **Cancelar** sólo antes de recibir. Cancelar una CxP de la OC deshace la
 *     recepción y recompone el estado de la OC.
 */

use App\Http\Controllers\CuentaPorPagarController;
use App\Http\Controllers\OrdenCompraController;
use App\Models\ControlEscolar\Ciclo;
use App\Models\Finanzas\CentroCosto;
use App\Models\Finanzas\CuentaPorPagar;
use App\Models\Finanzas\OrdenCompra;
use App\Models\Finanzas\PartidaPresupuesto;
use App\Models\Finanzas\Proveedor;
use App\Models\Identidad\Usuario;
use App\Models\Tenant;
use App\Services\Finanzas\GestorDeOrdenesCompra;
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

const PREF = 'ZZOC-';

$db->beginTransaction();

try {
    $admin = Usuario::query()->where('usuario', 'demo')->firstOrFail();
    $adminPersona = (int) $admin->persona_id;
    $ctrl = app(OrdenCompraController::class);
    $ctrlCxp = app(CuentaPorPagarController::class);
    $gestor = app(GestorDeOrdenesCompra::class);

    $prov = Proveedor::create(['nombre' => PREF.'Proveedor', 'activo' => true]);
    $centro = CentroCosto::create(['clave' => PREF.'C', 'nombre' => PREF.'Centro', 'activo' => true]);
    $partida = PartidaPresupuesto::create(['clave' => PREF.'P', 'nombre' => PREF.'Partida', 'activo' => true]);
    $ciclo = (int) Ciclo::query()->orderByDesc('id')->value('id');

    $base = fn (array $extra = []) => array_merge([
        'proveedor_id' => $prov->id, 'centro_costo_id' => $centro->id, 'partida_id' => $partida->id,
        'ciclo_id' => $ciclo, 'fecha' => now()->toDateString(),
        'conceptos' => [
            ['descripcion' => PREF.'A', 'cantidad' => 2, 'precio_unitario' => 100],
            ['descripcion' => PREF.'B', 'cantidad' => 1, 'precio_unitario' => 300],
        ],
    ], $extra);

    $recepcion = fn (float $monto) => ['monto' => $monto, 'fecha' => now()->toDateString(), 'vencimiento' => now()->addDays(15)->toDateString()];

    // ── 1. Alta y total ─────────────────────────────────────────────────────
    echo PHP_EOL.'1. El total sale de los conceptos'.PHP_EOL;

    $ctrl->guardar(peticionDe($admin, $base(['referencia' => PREF.'r1'])));
    $oc = OrdenCompra::query()->where('referencia', PREF.'r1')->latest('id')->firstOrFail();
    verificar('Nace en borrador', $oc->estado === OrdenCompra::BORRADOR);
    verificar('El total es la suma de los conceptos (500)', $oc->total() === 500.0);

    // ── 2. Autorizar ────────────────────────────────────────────────────────
    echo PHP_EOL.'2. Autorizar exige conceptos y sólo un borrador'.PHP_EOL;

    $ctrl->guardar(peticionDe($admin, $base(['referencia' => PREF.'vacia'])));
    $ocVacia = OrdenCompra::query()->where('referencia', PREF.'vacia')->latest('id')->firstOrFail();
    $ocVacia->conceptos()->delete();
    verificar('Autorizar una orden sin conceptos → 422', fallo(fn () => $gestor->autorizar($ocVacia, $adminPersona)) === 422);

    $ctrl->autorizar(peticionDe($admin), $oc);
    $oc->refresh();
    verificar('Autorizada, con quién y cuándo', $oc->estado === OrdenCompra::AUTORIZADA && (int) $oc->autorizada_por === $adminPersona && $oc->autorizada_en !== null);
    verificar('Autorizar de nuevo → 422', fallo(fn () => $gestor->autorizar($oc, $adminPersona)) === 422);

    // ── 3. Editar sólo en borrador ──────────────────────────────────────────
    echo PHP_EOL.'3. Sólo un borrador se edita'.PHP_EOL;

    verificar('Editar una orden autorizada → 422',
        fallo(fn () => $ctrl->guardar(peticionDe($admin, $base(['referencia' => PREF.'x'])), $oc)) === 422);

    // ── 4. Recibir genera la CxP ────────────────────────────────────────────
    echo PHP_EOL.'4. Recibir genera la cuenta por pagar'.PHP_EOL;

    $cxpAntes = CuentaPorPagar::query()->count();
    $gestor->recibir($oc, $recepcion(200));
    $oc->refresh();
    verificar('Recibida parcial: estado recibida, por recibir 300', $oc->estado === OrdenCompra::RECIBIDA && $oc->porRecibir() === 300.0);
    verificar('Generó UNA cuenta por pagar', CuentaPorPagar::query()->count() === $cxpAntes + 1);

    $cxp = CuentaPorPagar::query()->where('orden_compra_id', $oc->id)->latest('id')->firstOrFail();
    verificar('La CxP copia proveedor/centro/partida y su monto, con origen orden_compra',
        (int) $cxp->proveedor_id === $prov->id && (int) $cxp->centro_costo_id === $centro->id
        && (int) $cxp->partida_id === $partida->id && (float) $cxp->monto === 200.0 && $cxp->origen === CuentaPorPagar::ORIGEN_ORDEN_COMPRA);

    $gestor->recibir($oc, $recepcion(300));
    $oc->refresh();
    verificar('Recibida por completo → cerrada, por recibir 0', $oc->estado === OrdenCompra::CERRADA && $oc->porRecibir() === 0.0);
    verificar('Recibir una orden cerrada → 422', fallo(fn () => $gestor->recibir($oc, $recepcion(1))) === 422);

    // No se recibe de más.
    $ctrl->guardar(peticionDe($admin, $base(['referencia' => PREF.'r2'])));
    $oc2 = OrdenCompra::query()->where('referencia', PREF.'r2')->latest('id')->firstOrFail();
    $gestor->autorizar($oc2, $adminPersona);
    verificar('Recibir más que lo pedido → 422', fallo(fn () => $gestor->recibir($oc2, $recepcion(600))) === 422);

    // El bloqueo (for update) sobre la OC.
    $vioLock = false;
    DB::listen(function ($q) use (&$vioLock) {
        if (stripos($q->sql, 'for update') !== false && stripos($q->sql, 'ordenes_compra') !== false) {
            $vioLock = true;
        }
    });
    $gestor->recibir($oc2, $recepcion(100));
    verificar('Recibir bloquea la OC (SELECT ... FOR UPDATE)', $vioLock);

    // ── 5. Cancelar ─────────────────────────────────────────────────────────
    echo PHP_EOL.'5. Cancelar sólo antes de recibir'.PHP_EOL;

    $ctrl->guardar(peticionDe($admin, $base(['referencia' => PREF.'cancelaBorrador'])));
    $ocB = OrdenCompra::query()->where('referencia', PREF.'cancelaBorrador')->latest('id')->firstOrFail();
    $gestor->cancelar($ocB);
    verificar('Un borrador se cancela', $ocB->fresh()->estado === OrdenCompra::CANCELADA);

    $ctrl->guardar(peticionDe($admin, $base(['referencia' => PREF.'cancelaAuth'])));
    $ocA = OrdenCompra::query()->where('referencia', PREF.'cancelaAuth')->latest('id')->firstOrFail();
    $gestor->autorizar($ocA, $adminPersona);
    $gestor->cancelar($ocA);
    verificar('Una autorizada sin recepciones se cancela', $ocA->fresh()->estado === OrdenCompra::CANCELADA);

    // oc2 ya tiene una recepción (100).
    verificar('Cancelar una orden con recepciones → 422', fallo(fn () => $gestor->cancelar($oc2->fresh())) === 422);

    // ── 6. Cancelar la CxP recompone la OC ──────────────────────────────────
    echo PHP_EOL.'6. Cancelar la CxP deshace la recepción'.PHP_EOL;

    $ctrl->guardar(peticionDe($admin, $base(['referencia' => PREF.'rehacer'])));
    $ocR = OrdenCompra::query()->where('referencia', PREF.'rehacer')->latest('id')->firstOrFail();
    $gestor->autorizar($ocR, $adminPersona);
    $gestor->recibir($ocR, $recepcion(500));
    verificar('Recibida completa → cerrada', $ocR->fresh()->estado === OrdenCompra::CERRADA);

    $cxpR = CuentaPorPagar::query()->where('orden_compra_id', $ocR->id)->latest('id')->firstOrFail();
    $ctrlCxp->cancelar($cxpR);
    $ocR->refresh();
    verificar('Cancelada la CxP, la OC vuelve a autorizada con saldo por recibir',
        $ocR->estado === OrdenCompra::AUTORIZADA && $ocR->porRecibir() === 500.0);

    // ── 7. Permisos ─────────────────────────────────────────────────────────
    echo PHP_EOL.'7. Permisos: dos oficios y una puerta'.PHP_EOL;

    verificar('gestionar-ordenes-compra es administrativo', CatalogoPermisos::correspondeA('gestionar-ordenes-compra', 'administrativo'));
    verificar('autorizar-ordenes-compra es administrativo', CatalogoPermisos::correspondeA('autorizar-ordenes-compra', 'administrativo'));
    verificar('El admin ve las órdenes de compra (puerta derivada)', $admin->can('ver-ordenes-compra'));
} catch (Throwable $e) {
    verificar('La suite corrió hasta el final sin morir', false,
        get_class($e).': '.$e->getMessage().' @ '.basename($e->getFile()).':'.$e->getLine());
} finally {
    $db->rollBack();
}

echo PHP_EOL.'Resultado: '.($verificaciones - $fallidas).' correctas, '.$fallidas.' fallidas'.PHP_EOL;

exit($fallidas === 0 ? 0 : 1);
