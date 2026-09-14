<?php

declare(strict_types=1);

/*
 * Las operaciones financieras respetan el alcance de campus del rol.
 *
 * Filtrar el listado no basta: el id de la matrícula/pago/adeudo/factura llega
 * por la URL, así que cada acción tiene que cortar el paso a un registro de otro
 * campus. Aquí se acota a un usuario administrativo al campus A y se comprueba,
 * a través de los controladores, que las acciones sobre un registro del campus B
 * responden 403, que las del A pasan, y que un usuario GLOBAL no se topa con el
 * candado.
 */

use App\Http\Controllers\ComprobantePagoController;
use App\Http\Controllers\FacturaController;
use App\Http\Controllers\FinanzasController;
use App\Http\Controllers\SolicitudFacturaController;
use App\Models\Finanzas\Adeudo;
use App\Models\Finanzas\ComprobantePago;
use App\Models\Finanzas\ConceptoPago;
use App\Models\Finanzas\Factura;
use App\Models\Finanzas\SolicitudFactura;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\Identidad\PersonaRol;
use App\Models\Identidad\Usuario;
use App\Models\Tenant;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

/** true si la acción se cortó con un 403; false si pasó o falló por otra cosa. */
function bloqueado(callable $accion): bool
{
    try {
        $accion();
    } catch (HttpException $e) {
        return $e->getStatusCode() === 403;
    } catch (Throwable $e) {
        return false; // otra cosa (validación, etc.): no fue el candado de campus
    }

    return false;
}

function req(Usuario $usuario, array $datos = []): Request
{
    $p = Request::create('/', 'POST', $datos);
    $p->setUserResolver(fn () => $usuario);

    return $p;
}

$db->beginTransaction();

try {
    // ── Escenario: dos matrículas en campus distintos y un admin acotado a A ──
    $matA = MatriculaOferta::query()->whereHas('oferta', fn ($q) => $q->where('campus_id', 32))->with('oferta')->first();
    $matB = MatriculaOferta::query()->whereHas('oferta', fn ($q) => $q->where('campus_id', 33))->with('oferta')->first();
    if ($matA === null || $matB === null) {
        throw new RuntimeException('El demo necesita matrículas en los campus 32 y 33.');
    }
    $campusA = (int) $matA->oferta->campus_id;
    $campusB = (int) $matB->oferta->campus_id;

    $usuario = Usuario::findOrFail(1); // administrativo, rol activo administrativo
    PersonaRol::query()->where('persona_id', $usuario->persona_id)->where('rol_id', $usuario->rol_activo_id)
        ->update(['campus_id' => $campusA, 'activo' => true]);
    if (PersonaRol::query()->where('persona_id', $usuario->persona_id)->where('rol_id', $usuario->rol_activo_id)->doesntExist()) {
        PersonaRol::create(['persona_id' => $usuario->persona_id, 'rol_id' => $usuario->rol_activo_id, 'activo' => true, 'campus_id' => $campusA]);
    }

    verificar('El usuario quedó acotado al campus A',
        in_array($campusA, $usuario->campusVisibles() ?? [], true) && ! in_array($campusB, $usuario->campusVisibles() ?? [], true));

    $colegiatura = ConceptoPago::where('clave', 'colegiatura')->firstOrFail();
    $adeudoB = Adeudo::create([
        'matricula_oferta_id' => $matB->id, 'concepto_id' => $colegiatura->id, 'periodo_etiqueta' => 'Marzo 2026',
        'monto' => 500, 'monto_total' => 500, 'fecha_generacion' => '2026-03-01', 'fecha_vencimiento' => '2026-03-10',
    ]);
    $comprobanteB = ComprobantePago::create([
        'matricula_oferta_id' => $matB->id, 'monto' => 500, 'fecha_transferencia' => '2026-03-05',
        'archivo' => 'comprobantes/x.pdf', 'estado' => ComprobantePago::PENDIENTE, 'adeudo_ids' => [$adeudoB->id],
    ]);
    $comprobanteA = ComprobantePago::create([
        'matricula_oferta_id' => $matA->id, 'monto' => 500, 'fecha_transferencia' => '2026-03-05',
        'archivo' => 'comprobantes/y.pdf', 'estado' => ComprobantePago::PENDIENTE, 'adeudo_ids' => [],
    ]);
    $solicitudB = SolicitudFactura::create([
        'matricula_oferta_id' => $matB->id, 'pago_ids' => [], 'receptor_rfc' => 'GUME900101AB1',
        'receptor_razon_social' => 'X', 'receptor_uso_cfdi' => 'D10', 'receptor_regimen_fiscal' => '605', 'receptor_cp' => '44100',
    ]);
    $facturaB = new Factura;
    $facturaB->forceFill([
        'matricula_oferta_id' => $matB->id, 'receptor_rfc' => 'GUME900101AB1', 'receptor_razon_social' => 'X',
        'receptor_uso_cfdi' => 'D10', 'receptor_regimen_fiscal' => '605', 'receptor_cp' => '44100',
        'subtotal' => 100, 'total' => 100, 'estatus' => Factura::ESTATUS_BORRADOR,
    ])->save();

    $fin = app(FinanzasController::class);
    $fac = app(FacturaController::class);
    $comp = app(ComprobantePagoController::class);
    $sol = app(SolicitudFacturaController::class);

    // ── FinanzasController ───────────────────────────────────────────────────
    echo PHP_EOL.'1. Cartera: acciones sobre un alumno de otro campus → 403'.PHP_EOL;
    verificar('registrarPago del campus B se bloquea', bloqueado(fn () => $fin->registrarPago(req($usuario), $matB)));
    verificar('registrarPago del campus A pasa el candado', ! bloqueado(fn () => $fin->registrarPago(req($usuario), $matA)));
    verificar('generar del campus B se bloquea', bloqueado(fn () => $fin->generar(req($usuario), $matB)));
    verificar('cambiarSituacion del campus B se bloquea', bloqueado(fn () => $fin->cambiarSituacion(req($usuario), $matB)));
    verificar('resolverAdeudo de un adeudo del campus B se bloquea', bloqueado(fn () => $fin->resolverAdeudo(req($usuario), $adeudoB)));

    // ── FacturaController ────────────────────────────────────────────────────
    echo PHP_EOL.'2. Facturación: emitir/ver de otro campus → 403'.PHP_EOL;
    verificar('facturables del campus B se bloquea', bloqueado(fn () => $fac->facturables(req($usuario), $matB)));
    verificar('facturables del campus A pasa el candado', ! bloqueado(fn () => $fac->facturables(req($usuario), $matA)));
    verificar('store del campus B se bloquea', bloqueado(fn () => $fac->store(req($usuario), $matB)));
    verificar('show de una factura del campus B se bloquea', bloqueado(fn () => $fac->show(req($usuario), $facturaB)));
    verificar('descargar de una factura del campus B se bloquea', bloqueado(fn () => $fac->descargar(req($usuario), $facturaB, 'xml')));
    verificar('cancelar de una factura del campus B se bloquea', bloqueado(fn () => $fac->cancelar(req($usuario), $facturaB)));

    // ── ComprobantePagoController ────────────────────────────────────────────
    echo PHP_EOL.'3. Comprobantes: aprobar de otro campus → 403 y la cola se acota'.PHP_EOL;
    verificar('aprobar un comprobante del campus B se bloquea', bloqueado(fn () => $comp->aprobar(req($usuario), $comprobanteB)));
    verificar('rechazar un comprobante del campus B se bloquea', bloqueado(fn () => $comp->rechazar(req($usuario), $comprobanteB)));

    $pedir = Request::create('/', 'GET', [], [], [], ['HTTP_X_INERTIA' => 'true', 'HTTP_X_INERTIA_VERSION' => '']);
    $pedir->setUserResolver(fn () => $usuario);
    $props = json_decode($comp->index($pedir)->toResponse($pedir)->getContent(), true)['props']['comprobantes'] ?? [];
    $ids = array_column($props, 'id');
    verificar('La cola de comprobantes NO trae el del campus B', ! in_array($comprobanteB->id, $ids, true));
    verificar('La cola de comprobantes SÍ trae el del campus A', in_array($comprobanteA->id, $ids, true));

    // ── SolicitudFacturaController ───────────────────────────────────────────
    echo PHP_EOL.'4. Solicitudes de factura: emitir/rechazar de otro campus → 403'.PHP_EOL;
    verificar('emitir una solicitud del campus B se bloquea', bloqueado(fn () => $sol->emitir(req($usuario), $solicitudB)));
    verificar('rechazar una solicitud del campus B se bloquea', bloqueado(fn () => $sol->rechazar(req($usuario), $solicitudB)));

    // ── Control: un usuario GLOBAL no se topa con el candado ─────────────────
    echo PHP_EOL.'5. Un usuario GLOBAL (sin acotar) pasa el candado en cualquier campus'.PHP_EOL;
    PersonaRol::query()->where('persona_id', $usuario->persona_id)->where('rol_id', $usuario->rol_activo_id)
        ->update(['campus_id' => null]);
    verificar('Ahora el usuario es global', $usuario->campusVisibles() === null);
    verificar('registrarPago del campus B ya no se bloquea', ! bloqueado(fn () => $fin->registrarPago(req($usuario), $matB)));
    verificar('aprobar el comprobante del campus B ya no se bloquea', ! bloqueado(fn () => $comp->aprobar(req($usuario), $comprobanteB)));
} catch (Throwable $e) {
    verificar('La suite corrió hasta el final sin morir', false,
        get_class($e).': '.$e->getMessage().' @ '.basename($e->getFile()).':'.$e->getLine());
} finally {
    $db->rollBack();
}

echo PHP_EOL.'Resultado: '.($verificaciones - $fallidas).' correctas, '.$fallidas.' fallidas'.PHP_EOL;
