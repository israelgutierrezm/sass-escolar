<?php

/**
 * Factura global (R06, rebanada 2): un CFDI al público en general por lo cobrado
 * sin factura nominativa en un periodo. Contra la BD real, con rollback; el
 * timbrado en cola `sync` (PAC falso) y los XML se limpian.
 *
 * `php scripts/prueba-factura-global.php` desde la raíz.
 */

use App\Http\Controllers\FacturaController;
use App\Models\Academico\Oferta;
use App\Models\Finanzas\Adeudo;
use App\Models\Finanzas\ConceptoPago;
use App\Models\Finanzas\EmisorAsignacion;
use App\Models\Finanzas\EmisorFiscal;
use App\Models\Finanzas\Factura;
use App\Models\Finanzas\MetodoPago;
use App\Models\Finanzas\Pago;
use App\Models\Identidad\Persona;
use App\Models\Tenant;
use App\Services\Cfdi\FacturapiPac;
use App\Services\EmisorFactura;
use App\Services\MatriculadorOferta;
use App\Services\RegistradorPago;
use App\Support\PublicoEnGeneral;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
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

DB::beginTransaction();

try {
    $emisorSvc = app(EmisorFactura::class);
    $registrador = app(RegistradorPago::class);
    $efectivo = MetodoPago::where('clave', 'efectivo')->firstOrFail();
    $colegiatura = ConceptoPago::where('clave', 'colegiatura')->firstOrFail();

    // Una razón social global, para que todas las matrículas resuelvan a ella.
    $emisor = EmisorFiscal::create([
        'rfc' => 'AAA010101AAA', 'razon_social' => 'ESCUELA DEMO SC',
        'regimen_fiscal' => '601', 'cp' => '44100',
    ]);
    $emisor->asignaciones()->create(['aplica_a_tipo' => EmisorAsignacion::APLICA_GLOBAL]);

    // Dos alumnos, cada uno con un pago cobrado en MARZO 2026, sin facturar.
    $pagoDe = function (float $monto) use ($colegiatura, $registrador, $efectivo) {
        $persona = Persona::create(['nombre' => 'Ana', 'primer_apellido' => 'Pública', 'sexo_id' => 2]);
        $matricula = app(MatriculadorOferta::class)->matricular($persona, Oferta::firstOrFail(), '2026-2030');
        $adeudo = Adeudo::create([
            'matricula_oferta_id' => $matricula->id, 'concepto_id' => $colegiatura->id,
            'monto' => $monto, 'monto_total' => $monto,
            'fecha_generacion' => '2026-03-01', 'fecha_vencimiento' => '2026-03-10',
        ]);
        $pago = $registrador->registrar($matricula, $efectivo, $monto, [$adeudo->id]);
        Pago::whereKey($pago->id)->update(['momento' => '2026-03-15 10:00:00']);

        return [$matricula, $pago->fresh()];
    };

    [$mat1, $pago1] = $pagoDe(2000);
    [$mat2, $pago2] = $pagoDe(1500);

    // Un pago de ABRIL, que NO debe entrar a la global de marzo.
    [$mat3, $pago3] = $pagoDe(999);
    Pago::whereKey($pago3->id)->update(['momento' => '2026-04-15 10:00:00']);

    echo '1. globalizables: lo cobrado y no facturado del periodo, de este emisor'.PHP_EOL;
    $globalizables = $emisorSvc->globalizables($emisor, '2026-03-01', '2026-03-31');
    $ids = $globalizables->pluck('id')->sort()->values()->all();
    verificar('Trae los dos pagos de marzo', $ids === collect([$pago1->id, $pago2->id])->sort()->values()->all());
    verificar('Y NO el de abril', ! in_array($pago3->id, $ids, true));

    echo PHP_EOL.'2. emitirGlobal: UN CFDI al público en general, con InformacionGlobal'.PHP_EOL;
    $global = $emisorSvc->emitirGlobal($emisor, [$pago1->id, $pago2->id], '04', '03', 2026);
    if ($global->xml_ruta !== null) { $archivos[] = $global->xml_ruta; }
    $global->refresh();
    verificar('Es global', $global->esGlobal());
    verificar('Sin matrícula (agrupa muchas)', $global->matricula_oferta_id === null);
    verificar('Receptor genérico del SAT (preset)',
        $global->receptor_rfc === PublicoEnGeneral::RFC
        && $global->receptor_regimen_fiscal === PublicoEnGeneral::REGIMEN
        && $global->receptor_uso_cfdi === PublicoEnGeneral::USO_CFDI);
    verificar('Con su periodicidad, mes y año', $global->periodicidad_global === '04' && $global->periodo_global_meses === '03' && (int) $global->periodo_global_anio === 2026);
    verificar('Un renglón por pago', $global->conceptos()->count() === 2);
    verificar('El total es lo cobrado', (float) $global->total === 3500.0);
    verificar('Se timbró con folio', $global->uuid !== null);
    verificar('Sin complemento educativo (no es nominativa)', $global->iedu === null);

    echo PHP_EOL.'3. El CORTE: los pagos globalizados ya no se facturan nominativos'.PHP_EOL;
    verificar('El pago 1 ya no es facturable', ! $emisorSvc->facturables($mat1->id)->pluck('id')->contains($pago1->id));
    verificar('Ni aparece ya en globalizables', $emisorSvc->globalizables($emisor, '2026-03-01', '2026-03-31')->isEmpty());

    echo PHP_EOL.'4. El bloque «global» sólo viaja en la global'.PHP_EOL;
    $pac = app(FacturapiPac::class);
    $cuerpoGlobal = $pac->cuerpoDe($global);
    verificar('La global lleva InformacionGlobal', isset($cuerpoGlobal['global']['periodicity']) && $cuerpoGlobal['global']['periodicity'] === '04');
    // Una nominativa NO lo lleva.
    $nominativa = $emisorSvc->emitir($mat3->id, [$pago3->id], [
        'rfc' => 'GUME900101AB1', 'razon_social' => 'ANA', 'uso_cfdi' => 'G03', 'regimen_fiscal' => '616', 'cp' => '44100',
    ]);
    if ($nominativa->xml_ruta !== null) { $archivos[] = $nominativa->xml_ruta; }
    verificar('Una nominativa NO lleva bloque global', ! isset($pac->cuerpoDe($nominativa->fresh())['global']));

    echo PHP_EOL.'5. El controlador deriva los pagos del periodo y emite'.PHP_EOL;
    // Un cuarto pago de MAYO para probar el controlador aparte.
    [$mat4, $pago4] = $pagoDe(700);
    Pago::whereKey($pago4->id)->update(['momento' => '2026-05-15 10:00:00']);

    $ctrl = app(FacturaController::class);
    $req = Request::create('/', 'POST', ['emisor_id' => $emisor->id, 'periodicidad' => '04', 'mes' => 5, 'anio' => 2026]);
    app()->instance('request', $req);
    $ctrl->emitirGlobal($req);
    $globalMayo = Factura::where('es_global', true)->where('periodo_global_meses', '05')->first();
    if ($globalMayo && $globalMayo->xml_ruta !== null) { $archivos[] = $globalMayo->xml_ruta; }
    verificar('Nació la global de mayo con el pago del periodo',
        $globalMayo !== null && $globalMayo->conceptos()->where('pago_id', $pago4->id)->exists());
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
