<?php

/**
 * Datos fiscales del emisor (razón social): alta y edición con los campos
 * nuevos de Facturapi (nombre comercial, contacto, domicilio, predeterminados
 * de CFDI). Contra la BD real, con rollback.
 *
 * `php scripts/prueba-emisor-datos-fiscales.php` desde la raíz.
 */

$raiz = dirname(__DIR__);

require $raiz.'/vendor/autoload.php';
$app = require $raiz.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Controllers\EmisorFiscalController;
use App\Models\Finanzas\EmisorFiscal;
use App\Support\CatalogosSat;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

tenancy()->initialize(App\Models\Tenant::find('demo'));

$ok = 0;
$fallos = [];

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

function req(array $datos, string $metodo = 'POST'): Request
{
    $r = Request::create('/', $metodo, $datos);
    app()->instance('request', $r);

    return $r;
}

DB::beginTransaction();

try {
    $ctrl = new EmisorFiscalController;
    $rfc = 'DEM'.random_int(100000, 999999).'AB1';

    echo '1. Alta con datos fiscales completos'.PHP_EOL;
    $ctrl->store(req([
        'rfc' => $rfc, 'razon_social' => 'Instituto Demo SA de CV', 'nombre_comercial' => 'IDEMO',
        'regimen_fiscal' => '601', 'cp' => '64000', 'correo_fiscal' => 'facturas@idemo.mx', 'telefono' => '8110002000',
        'calle' => 'Reforma', 'num_exterior' => '100', 'colonia' => 'Centro', 'municipio' => 'Monterrey', 'estado' => 'Nuevo León', 'pais' => 'MEX',
        'uso_cfdi_default' => 'D10', 'serie_default' => 'A', 'folio_inicial' => 100, 'moneda_default' => 'MXN',
        'forma_pago_default' => '03', 'metodo_pago_default' => 'PUE', 'exportacion_default' => '01', 'objeto_impuesto_default' => '02',
        'facturapi_id' => 'org_ABC', 'activo' => true,
    ]));

    $e = EmisorFiscal::where('rfc', $rfc)->first();
    verificar('Se creó la razón social', $e !== null);
    verificar('Guardó nombre comercial y contacto', $e?->nombre_comercial === 'IDEMO' && $e?->correo_fiscal === 'facturas@idemo.mx' && $e?->telefono === '8110002000');
    verificar('Guardó el domicilio', $e?->calle === 'Reforma' && $e?->municipio === 'Monterrey' && $e?->estado === 'Nuevo León');
    verificar('Guardó los predeterminados de CFDI', $e?->uso_cfdi_default === 'D10' && $e?->metodo_pago_default === 'PUE' && $e?->objeto_impuesto_default === '02');
    verificar('Folio inicial es entero', $e?->folio_inicial === 100);
    verificar('Guardó el id de Facturapi', $e?->facturapi_id === 'org_ABC');

    echo PHP_EOL.'2. Edición (update) cambia los datos fiscales'.PHP_EOL;
    $ctrl->update(req([
        'rfc' => $rfc, 'razon_social' => 'Instituto Demo SA de CV', 'nombre_comercial' => 'IDEMO 2',
        'regimen_fiscal' => '603', 'cp' => '64010', 'serie_default' => 'B', 'objeto_impuesto_default' => '01',
        'activo' => true,
    ], 'PUT'), $e);

    $e->refresh();
    verificar('Actualizó nombre comercial y régimen', $e->nombre_comercial === 'IDEMO 2' && $e->regimen_fiscal === '603' && $e->cp === '64010');
    verificar('Actualizó serie y objeto de impuesto', $e->serie_default === 'B' && $e->objeto_impuesto_default === '01');

    echo PHP_EOL.'3. El domicilio es opcional'.PHP_EOL;
    $rfc2 = 'DEM'.random_int(100000, 999999).'CD2';
    $ctrl->store(req([
        'rfc' => $rfc2, 'razon_social' => 'Sin Domicilio SA', 'regimen_fiscal' => '626', 'cp' => '01000', 'activo' => true,
    ]));
    $e2 = EmisorFiscal::where('rfc', $rfc2)->first();
    verificar('Alta sin domicilio funciona', $e2 !== null && $e2->calle === null);

    echo PHP_EOL.'4. El catálogo SAT trae los grupos esperados'.PHP_EOL;
    $cat = CatalogosSat::todos();
    verificar('Trae regímenes, usos y objeto de impuesto',
        isset($cat['regimenes_fiscales'], $cat['usos_cfdi'], $cat['objeto_impuesto']) && count($cat['regimenes_fiscales']) > 0);
} finally {
    DB::rollBack();
    echo PHP_EOL.'-- rollback aplicado, la base queda como estaba --'.PHP_EOL;
}

echo PHP_EOL.'Resultado: '.$ok.' correctas, '.count($fallos).' fallidas'.PHP_EOL;

if ($fallos !== []) {
    echo 'Fallaron: '.implode(' · ', $fallos).PHP_EOL;
}

exit($fallos === [] ? 0 : 1);
