<?php

declare(strict_types=1);

/*
 * Una cancelación que el SAT deja EN PROCESO no se trata como definitiva.
 *
 * Al cancelar un CFDI de monto que exige la aceptación del receptor, el SAT lo
 * deja pendiente y el comprobante sigue VIVO. `cancelar` marcaba `cancelada` de
 * inmediato, y como `vivas()` excluye lo cancelado, sus pagos volvían a ser
 * facturables: el mismo dinero podía declararse dos veces mientras el CFDI
 * seguía vigente. Aquí se comprueba que una cancelación pendiente NO libera los
 * pagos, y que la definitiva sí.
 */

use App\Models\Academico\Oferta;
use App\Models\Finanzas\Adeudo;
use App\Models\Finanzas\ConceptoPago;
use App\Models\Finanzas\EmisorAsignacion;
use App\Models\Finanzas\EmisorFiscal;
use App\Models\Finanzas\Factura;
use App\Models\Finanzas\MetodoPago;
use App\Models\Identidad\Persona;
use App\Models\Tenant;
use App\Services\Cfdi\EstadoEnElPac;
use App\Services\Cfdi\Pac;
use App\Services\Cfdi\PacFalso;
use App\Services\Cfdi\ResultadoTimbrado;
use App\Services\EmisorFactura;
use App\Services\MatriculadorOferta;
use App\Services\RegistradorPago;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

$raiz = dirname(__DIR__);
require $raiz.'/vendor/autoload.php';
$app = require $raiz.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

tenancy()->initialize(Tenant::find('demo'));
config(['queue.default' => 'sync']); // el timbrado corre inline

$ok = 0;
$fallidas = 0;
$archivos = [];

function verificar(string $que, bool $cond, string $detalle = ''): void
{
    global $ok, $fallidas;
    $cond ? $ok++ : $fallidas++;
    echo ($cond ? "  \033[32mOK\033[39m   " : "  \033[31mFALLA\033[39m ").$que.($detalle !== '' ? "  [{$detalle}]" : '').PHP_EOL;
}

$receptor = ['rfc' => 'GUME900101AB1', 'razon_social' => 'MARIA GUTIERREZ MENDOZA', 'uso_cfdi' => 'D10', 'regimen_fiscal' => '605', 'cp' => '44100'];

DB::beginTransaction();

try {
    $emisor = app(EmisorFactura::class);
    $registrador = app(RegistradorPago::class);
    $efectivo = MetodoPago::where('clave', 'efectivo')->firstOrFail();
    $colegiatura = ConceptoPago::where('clave', 'colegiatura')->firstOrFail();

    $emisorEscuela = EmisorFiscal::create([
        'rfc' => 'AAA010101AAA', 'razon_social' => 'ESCUELA DEMO SC', 'regimen_fiscal' => '603', 'cp' => '44100',
    ]);
    $emisorEscuela->asignaciones()->create(['aplica_a_tipo' => EmisorAsignacion::APLICA_GLOBAL]);

    $persona = Persona::create(['nombre' => 'María', 'primer_apellido' => 'Gutiérrez', 'sexo_id' => 2]);
    $matricula = app(MatriculadorOferta::class)->matricular($persona, Oferta::firstOrFail(), '2026-2030');

    $adeudo = Adeudo::create([
        'matricula_oferta_id' => $matricula->id, 'concepto_id' => $colegiatura->id, 'periodo_etiqueta' => 'Marzo 2026',
        'monto' => 2000, 'monto_total' => 2000, 'fecha_generacion' => '2026-03-01', 'fecha_vencimiento' => '2026-03-10',
    ]);
    $pago = $registrador->registrar($matricula, $efectivo, 2000.00, [$adeudo->id]);

    $factura = $emisor->emitir($matricula->id, [$pago->id], $receptor)->refresh();
    if ($factura->xml_ruta !== null) {
        $archivos[] = $factura->xml_ruta;
    }
    verificar('La factura se timbró', $factura->estatus === Factura::ESTATUS_TIMBRADA, $factura->estatus);
    verificar('Su pago NO es facturable (lo ocupa la factura viva)',
        $emisor->facturables($matricula->id)->count() === 0);

    // ── 1. Cancelación que el SAT deja EN PROCESO ────────────────────────────
    echo PHP_EOL.'1. Una cancelación pendiente NO libera los pagos'.PHP_EOL;

    app()->instance(Pac::class, new class extends PacFalso
    {
        public function cancelar(Factura $factura, string $motivo, ?string $uuidSustituta = null): ResultadoTimbrado
        {
            return ResultadoTimbrado::cancelado(pendiente: true);
        }
    });
    $emisorPend = app()->make(EmisorFactura::class);

    $pendiente = $emisorPend->cancelar($factura->fresh(), Factura::MOTIVO_SIN_RELACION);
    $factura->refresh();

    verificar('cancelar() avisa que quedó pendiente', $pendiente === true);
    verificar('La factura SIGUE timbrada (no se dio por cancelada)',
        $factura->estatus === Factura::ESTATUS_TIMBRADA, $factura->estatus);
    verificar('Queda anotado el trámite pendiente',
        $factura->sat_estado_cancelacion === EstadoEnElPac::CANCELACION_PENDIENTE
        && $factura->motivo_cancelacion === Factura::MOTIVO_SIN_RELACION);
    verificar('El pago SIGUE ocupado: NO se puede refacturar mientras el CFDI vive',
        $emisor->facturables($matricula->id)->count() === 0);

    // ── 2. Cuando el SAT confirma, la cancelación sí es definitiva ───────────
    echo PHP_EOL.'2. Cuando el SAT la acepta, se finaliza y libera los pagos'.PHP_EOL;

    app()->instance(Pac::class, new PacFalso); // el PAC falso confirma la cancelación
    $emisorHecho = app()->make(EmisorFactura::class);

    $pendiente2 = $emisorHecho->cancelar($factura->fresh(), Factura::MOTIVO_SIN_RELACION);
    $factura->refresh();

    verificar('cancelar() ya no reporta pendiente', $pendiente2 === false);
    verificar('La factura queda cancelada', $factura->estatus === Factura::ESTATUS_CANCELADA);
    verificar('Ahora sí se libera el pago (vuelve a ser facturable)',
        $emisor->facturables($matricula->id)->pluck('id')->all() === [$pago->id]);
} catch (Throwable $e) {
    verificar('La suite corrió hasta el final sin morir', false,
        get_class($e).': '.$e->getMessage().' @ '.basename($e->getFile()).':'.$e->getLine());
} finally {
    foreach ($archivos as $ruta) {
        Storage::disk('local')->delete($ruta);
    }
    DB::rollBack();
}

echo PHP_EOL.'Resultado: '.$ok.' correctas, '.$fallidas.' fallidas'.PHP_EOL;
