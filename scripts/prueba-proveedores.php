<?php

/**
 * Compras y CxP, rebanada 1: proveedores. Con rollback.
 * Ver `docs/plan-compras-cxp.md`.
 *
 * Se corre con `php scripts/prueba-proveedores.php` desde la raíz.
 *
 * ── Qué se vigila ─────────────────────────────────────────────────────────
 *  1. **El RFC es único** (pero opcional): el mismo proveedor dos veces reparte
 *     sus egresos entre duplicados. Editar el propio no choca consigo mismo.
 *  2. **Se apaga, no se borra**; el índice oculta los inactivos salvo que se
 *     pidan.
 *  3. **Estructura el egreso**: un egreso apunta a su proveedor, y el formulario
 *     de egresos sólo ofrece proveedores ACTIVOS.
 */

use App\Http\Controllers\EgresoController;
use App\Http\Controllers\ProveedorController;
use App\Models\ControlEscolar\Ciclo;
use App\Models\Finanzas\CentroCosto;
use App\Models\Finanzas\Egreso;
use App\Models\Finanzas\PartidaPresupuesto;
use App\Models\Finanzas\Proveedor;
use App\Models\Identidad\Usuario;
use App\Models\Tenant;
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

function props(Usuario $usuario, callable $llamar): array
{
    $p = Request::create('/', 'GET');
    $p->setUserResolver(fn () => $usuario);
    $p->headers->set('X-Inertia', 'true');
    $p->headers->set('X-Inertia-Version', '');

    return json_decode($llamar($p)->toResponse($p)->getContent(), true)['props'];
}

const PREF = 'ZZPROV-';

$db->beginTransaction();

try {
    $admin = Usuario::query()->where('usuario', 'demo')->firstOrFail();
    $ctrl = app(ProveedorController::class);
    $rfc = 'ZZ'.random_int(100000000, 999999999).'X';

    // ── 1. Alta, RFC único, edición ─────────────────────────────────────────
    echo PHP_EOL.'1. Alta, RFC único, edición'.PHP_EOL;

    $ctrl->guardar(peticionDe($admin, ['nombre' => PREF.'Uno', 'rfc' => $rfc]));
    $prov1 = Proveedor::query()->where('nombre', PREF.'Uno')->firstOrFail();
    verificar('Se creó el proveedor', $prov1->activo === true && $prov1->rfc === $rfc);

    verificar('Un RFC duplicado se rehúsa (422)',
        fallo(fn () => $ctrl->guardar(peticionDe($admin, ['nombre' => PREF.'Duplicado', 'rfc' => $rfc]))) === 422);

    $ctrl->guardar(peticionDe($admin, ['nombre' => PREF.'Uno editado', 'rfc' => $rfc]), $prov1);
    verificar('Editar el propio no choca con su mismo RFC', $prov1->fresh()->nombre === PREF.'Uno editado');

    $ctrl->guardar(peticionDe($admin, ['nombre' => PREF.'Sin RFC A', 'rfc' => null]));
    $ctrl->guardar(peticionDe($admin, ['nombre' => PREF.'Sin RFC B', 'rfc' => null]));
    verificar('Dos proveedores sin RFC conviven',
        Proveedor::query()->where('nombre', 'like', PREF.'Sin RFC%')->count() === 2);

    // ── 2. Apagar, no borrar; el índice ─────────────────────────────────────
    echo PHP_EOL.'2. Se apaga, no se borra'.PHP_EOL;

    $ctrl->alternar($prov1);
    verificar('Alternar lo desactiva', $prov1->fresh()->activo === false);
    $ctrl->alternar($prov1);
    verificar('Alternar de nuevo lo reactiva', $prov1->fresh()->activo === true);

    // Desactivamos «Sin RFC A» para el filtro.
    $provA = Proveedor::query()->where('nombre', PREF.'Sin RFC A')->firstOrFail();
    $ctrl->alternar($provA);

    $activos = collect(props($admin, fn ($p) => $ctrl->index($p))['proveedores']);
    verificar('El índice oculta inactivos por omisión',
        $activos->firstWhere('nombre', PREF.'Uno editado') !== null
        && $activos->firstWhere('nombre', PREF.'Sin RFC A') === null);

    $conInactivos = collect(props($admin, fn ($p) => $ctrl->index($p->merge(['inactivos' => 1])))['proveedores']);
    verificar('Con el filtro se ven los inactivos',
        $conInactivos->firstWhere('nombre', PREF.'Sin RFC A') !== null);

    // ── 3. Estructura el egreso ─────────────────────────────────────────────
    echo PHP_EOL.'3. Estructura el egreso'.PHP_EOL;

    $centro = CentroCosto::create(['clave' => PREF.'C', 'nombre' => PREF.'Centro', 'activo' => true]);
    $partida = PartidaPresupuesto::create(['clave' => PREF.'P', 'nombre' => PREF.'Partida', 'activo' => true]);
    $ciclo = (int) Ciclo::query()->orderByDesc('id')->value('id');

    $egreso = Egreso::create([
        'fecha' => now()->toDateString(), 'centro_costo_id' => $centro->id, 'proveedor_id' => $prov1->id,
        'partida_id' => $partida->id, 'ciclo_id' => $ciclo, 'monto' => 1500.50, 'descripcion' => PREF.'compra',
    ]);
    verificar('El egreso apunta a su proveedor', (int) $egreso->proveedor?->id === $prov1->id);

    $listado = collect(props($admin, fn ($p) => $ctrl->index($p))['proveedores']);
    $filaProv1 = Proveedor::query()->whereKey($prov1->id)->withCount('egresos')->first();
    verificar('El proveedor cuenta sus egresos', $filaProv1->egresos_count === 1);

    // El formulario de egresos sólo ofrece proveedores ACTIVOS.
    $ctrlEgreso = app(EgresoController::class);
    $propsEgreso = props($admin, fn ($p) => $ctrlEgreso->index($p));
    $provsEgreso = collect($propsEgreso['proveedores']);
    verificar('El formulario de egresos ofrece al activo', $provsEgreso->firstWhere('texto', PREF.'Uno editado') !== null);
    verificar('...y NO al inactivo', $provsEgreso->firstWhere('texto', PREF.'Sin RFC A') === null);
    verificar('El egreso lista el nombre de su proveedor',
        collect($propsEgreso['egresos'])->firstWhere('proveedor', PREF.'Uno editado') !== null);

    verificar('gestionar-proveedores es de la faceta administrativa',
        CatalogoPermisos::correspondeA('gestionar-proveedores', 'administrativo'));
} catch (Throwable $e) {
    verificar('La suite corrió hasta el final sin morir', false,
        get_class($e).': '.$e->getMessage().' @ '.basename($e->getFile()).':'.$e->getLine());
} finally {
    $db->rollBack();
}

echo PHP_EOL.'Resultado: '.($verificaciones - $fallidas).' correctas, '.$fallidas.' fallidas'.PHP_EOL;

exit($fallidas === 0 ? 0 : 1);
