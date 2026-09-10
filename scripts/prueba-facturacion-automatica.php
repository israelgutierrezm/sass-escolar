<?php

/**
 * Facturación automática al confirmar el pago (R06, rebanada 4): el evento
 * `PagoConfirmado` (una sola señal para todos los canales) dispara la política —
 * nominativo con perfil, público general si no—. Contra la BD real, con
 * rollback; timbrado en cola `sync` (PAC falso), XML limpiados.
 *
 * `php scripts/prueba-facturacion-automatica.php` desde la raíz.
 */

use App\Configuracion\Ajustes;
use App\Configuracion\CatalogoAjustes;
use App\Models\Academico\Oferta;
use App\Models\Finanzas\Adeudo;
use App\Models\Finanzas\ConceptoPago;
use App\Models\Finanzas\DatosFacturacion;
use App\Models\Finanzas\EmisorAsignacion;
use App\Models\Finanzas\EmisorFiscal;
use App\Models\Finanzas\Factura;
use App\Models\Finanzas\FacturaConcepto;
use App\Models\Finanzas\MetodoPago;
use App\Models\Finanzas\Pago;
use App\Models\Identidad\Persona;
use App\Models\Tenant;
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
config(['queue.default' => 'sync']);

$ok = 0;
$fallos = [];
$archivos = [];

function verificar(string $titulo, bool $condicion, string $detalle = ''): void
{
    global $ok, $fallos;
    if ($condicion) {
        $ok++;
        echo "  OK   {$titulo}".($detalle !== '' ? "  [{$detalle}]" : '').PHP_EOL;
    } else {
        $fallos[] = $titulo;
        echo "  FALLA {$titulo}".($detalle !== '' ? "  [{$detalle}]" : '').PHP_EOL;
    }
}

/** La factura que ampara un pago, si nació. */
function facturaDe(int $pagoId): ?Factura
{
    $facturaId = FacturaConcepto::where('pago_id', $pagoId)->value('factura_id');

    return $facturaId === null ? null : Factura::find($facturaId);
}

DB::beginTransaction();

try {
    $registrador = app(RegistradorPago::class);
    $emisorSvc = app(EmisorFactura::class);
    $transferencia = MetodoPago::firstOrCreate(['clave' => 'transferencia'], ['nombre' => 'Transferencia', 'requiere_confirmacion' => true, 'activo' => true]);
    $colegiatura = ConceptoPago::where('clave', 'colegiatura')->firstOrFail();

    $emisor = EmisorFiscal::create(['rfc' => 'AAA010101AAA', 'razon_social' => 'ESCUELA DEMO SC', 'regimen_fiscal' => '601', 'cp' => '44100']);
    $emisor->asignaciones()->create(['aplica_a_tipo' => EmisorAsignacion::APLICA_GLOBAL]);

    // Un alumno CON perfil fiscal (quiere factura, completo) y otro SIN.
    $alumnoDe = function (bool $conPerfil) use ($colegiatura) {
        $persona = Persona::create(['nombre' => 'Ana', 'primer_apellido' => 'Auto', 'sexo_id' => 2]);
        $matricula = app(MatriculadorOferta::class)->matricular($persona, Oferta::firstOrFail(), '2026-2030');
        if ($conPerfil) {
            DatosFacturacion::create([
                'persona_id' => $persona->id, 'quiere_factura' => true,
                'rfc' => 'GUME900101AB1', 'razon_social' => 'ANA AUTO', 'regimen_fiscal' => '616',
                'cp' => '44100', 'uso_cfdi' => 'G03',
            ]);
        }

        return $matricula;
    };

    $conf = function ($matricula, float $monto) use ($colegiatura, $registrador, $transferencia) {
        $adeudo = Adeudo::create(['matricula_oferta_id' => $matricula->id, 'concepto_id' => $colegiatura->id, 'monto' => $monto, 'monto_total' => $monto, 'fecha_generacion' => '2026-03-01', 'fecha_vencimiento' => '2026-03-10']);
        $pago = $registrador->registrar($matricula, $transferencia, $monto, [$adeudo->id]);
        Pago::whereKey($pago->id)->update(['momento' => '2026-03-15 10:00:00']);

        return $pago->fresh();
    };

    $matConPerfil = $alumnoDe(true);
    $matSinPerfil = $alumnoDe(false);

    echo '1. Con el automático APAGADO, confirmar no factura'.PHP_EOL;
    app(Ajustes::class)->guardar([CatalogoAjustes::FACTURA_AUTOMATICA => false]);
    $pagoA = $conf($matConPerfil, 1000);
    $registrador->confirmar($pagoA);
    verificar('No nació factura', facturaDe($pagoA->id) === null);

    echo PHP_EOL.'2. Con el automático ENCENDIDO y perfil, nace el CFDI nominativo'.PHP_EOL;
    app(Ajustes::class)->guardar([CatalogoAjustes::FACTURA_AUTOMATICA => true]);
    $pagoB = $conf($matConPerfil, 1200);
    $registrador->confirmar($pagoB);
    $facturaB = facturaDe($pagoB->id);
    if ($facturaB && $facturaB->xml_ruta !== null) { $archivos[] = $facturaB->xml_ruta; }
    verificar('Nació la factura del pago', $facturaB !== null);
    verificar('Nominativa, con el RFC del perfil', $facturaB?->receptor_rfc === 'GUME900101AB1' && ! $facturaB?->esGlobal());
    verificar('Y se timbró (misma cola del PAC)', $facturaB?->uuid !== null);
    verificar('El pago quedó facturado', ! $emisorSvc->facturables($matConPerfil->id)->pluck('id')->contains($pagoB->id));

    echo PHP_EOL.'3. Encendido pero SIN perfil, no se factura al vuelo (queda para la global)'.PHP_EOL;
    $pagoC = $conf($matSinPerfil, 800);
    $registrador->confirmar($pagoC);
    verificar('No nació factura nominativa', facturaDe($pagoC->id) === null);
    verificar('Y SÍ entra a la global del periodo', $emisorSvc->globalizables($emisor, '2026-03-01', '2026-03-31')->pluck('id')->contains($pagoC->id));

    echo PHP_EOL.'4. Quien pidió factura pero no la genera automática, NO entra a la global'.PHP_EOL;
    // El pago A (del alumno con perfil) sigue sin factura —se confirmó con el
    // automático apagado— y su alumno quiere nominativa: no debe colarse a la global.
    verificar('El pago del que quiere nominativa queda fuera de la global',
        ! $emisorSvc->globalizables($emisor, '2026-03-01', '2026-03-31')->pluck('id')->contains($pagoA->id));

    echo PHP_EOL.'5. No refactura: confirmar dos veces no emite otra'.PHP_EOL;
    $antes = Factura::whereHas('conceptos', fn ($q) => $q->where('pago_id', $pagoB->id))->count();
    $registrador->confirmar($pagoB->fresh()); // ya COMPLETADO: no hace nada
    verificar('Sigue habiendo una sola factura del pago', Factura::whereHas('conceptos', fn ($q) => $q->where('pago_id', $pagoB->id))->count() === $antes);
} catch (Throwable $e) {
    echo PHP_EOL.'EXCEPCIÓN: '.$e->getMessage().PHP_EOL.$e->getFile().':'.$e->getLine().PHP_EOL;
    $fallos[] = 'excepción: '.$e->getMessage();
} finally {
    DB::rollBack();
    foreach ($archivos as $ruta) { Storage::disk('local')->delete($ruta); }
    echo PHP_EOL.'-- rollback aplicado, la base queda como estaba --'.PHP_EOL;
}

echo PHP_EOL."Resultado: {$ok} correctas, ".count($fallos).' fallidas'.PHP_EOL;
foreach ($fallos as $f) { echo "  - {$f}".PHP_EOL; }

exit($fallos === [] ? 0 : 1);
