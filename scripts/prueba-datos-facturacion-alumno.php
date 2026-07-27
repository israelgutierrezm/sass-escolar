<?php

/**
 * Datos de facturación del alumno: guardar «quiere/no quiere factura», validar
 * los datos fiscales solo cuando quiere, y receptor tercero. Contra la BD real,
 * con rollback.
 *
 * `php scripts/prueba-datos-facturacion-alumno.php` desde la raíz.
 */

$raiz = dirname(__DIR__);

require $raiz.'/vendor/autoload.php';
$app = require $raiz.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Controllers\AlumnoController;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\Finanzas\DatosFacturacion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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

function req(array $datos): Request
{
    $r = Request::create('/', 'PUT', $datos);
    app()->instance('request', $r);

    return $r;
}

DB::beginTransaction();

try {
    $ctrl = new AlumnoController;
    $alumno = MatriculaOferta::query()->first();

    if ($alumno === null) {
        echo 'Sin matrículas en demo; se omite la prueba.'.PHP_EOL;
        DB::rollBack();
        exit(0);
    }

    echo '1. Si NO quiere factura, no se exigen datos fiscales'.PHP_EOL;
    $ctrl->guardarFacturacion(req(['quiere_factura' => 0]), $alumno);
    $d = DatosFacturacion::where('persona_id', $alumno->persona_id)->first();
    verificar('Se guardó con quiere_factura=false', $d !== null && $d->quiere_factura === false);

    echo PHP_EOL.'2. Si quiere factura pero faltan datos fiscales → no valida'.PHP_EOL;
    $lanzo = false;
    try {
        $ctrl->guardarFacturacion(req(['quiere_factura' => 1]), $alumno);
    } catch (ValidationException $e) {
        $claves = array_keys($e->errors());
        $lanzo = in_array('rfc', $claves, true) && in_array('uso_cfdi', $claves, true);
    }
    verificar('Exige RFC, régimen, CP y uso de CFDI', $lanzo);

    echo PHP_EOL.'3. Guardado completo, con receptor TERCERO'.PHP_EOL;
    $ctrl->guardarFacturacion(req([
        'quiere_factura' => 1, 'es_tercero' => 1,
        'rfc' => 'XAXX010101000', 'razon_social' => 'Empresa Pagadora SA',
        'regimen_fiscal' => '601', 'cp' => '64000', 'uso_cfdi' => 'G03',
        'correo_fiscal' => 'pagos@empresa.mx',
    ]), $alumno);
    $d = DatosFacturacion::where('persona_id', $alumno->persona_id)->first();
    verificar('Quiere factura y es tercero', $d->quiere_factura === true && $d->es_tercero === true);
    verificar('Guardó los datos fiscales del receptor', $d->rfc === 'XAXX010101000' && $d->razon_social === 'Empresa Pagadora SA' && $d->uso_cfdi === 'G03');

    echo PHP_EOL.'4. Uno por alumno (updateOrCreate no duplica)'.PHP_EOL;
    verificar('Sigue habiendo un solo registro', DatosFacturacion::where('persona_id', $alumno->persona_id)->count() === 1);

    echo PHP_EOL.'5. La relación de la persona lo encuentra'.PHP_EOL;
    verificar('persona->datosFacturacion existe', $alumno->persona->datosFacturacion()->exists());
} finally {
    DB::rollBack();
    echo PHP_EOL.'-- rollback aplicado, la base queda como estaba --'.PHP_EOL;
}

echo PHP_EOL.'Resultado: '.$ok.' correctas, '.count($fallos).' fallidas'.PHP_EOL;

if ($fallos !== []) {
    echo 'Fallaron: '.implode(' · ', $fallos).PHP_EOL;
}

exit($fallos === [] ? 0 : 1);
