<?php

/**
 * Conceptos de pago con datos fiscales: objeto de impuesto en alta/edición,
 * default de la migración en filas existentes, y que un concepto en uso no se
 * borra. Contra la BD real, con rollback.
 *
 * `php scripts/prueba-conceptos-fiscales.php` desde la raíz.
 */

$raiz = dirname(__DIR__);

require $raiz.'/vendor/autoload.php';
$app = require $raiz.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Controllers\ConceptoPagoController;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\Finanzas\Adeudo;
use App\Models\Finanzas\ConceptoPago;
use App\Models\Finanzas\SituacionPago;
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
    $ctrl = new ConceptoPagoController;

    echo '1. Las filas existentes quedaron con objeto de impuesto por default'.PHP_EOL;
    $existente = ConceptoPago::query()->first();
    verificar('Un concepto sembrado ya trae objeto_impuesto', $existente !== null && $existente->objeto_impuesto !== null, (string) $existente?->objeto_impuesto);

    echo PHP_EOL.'2. Alta con objeto de impuesto y datos fiscales'.PHP_EOL;
    $clave = 'prueba_'.random_int(1000, 9999);
    $ctrl->store(req([
        'clave' => $clave, 'nombre' => 'Concepto de prueba',
        'clave_sat' => '86121600', 'clave_unidad_sat' => 'E48',
        'objeto_impuesto' => '02', 'gravado' => true, 'tasa_iva' => 0.16,
    ]));
    $c = ConceptoPago::where('clave', $clave)->first();
    verificar('Se creó con objeto_impuesto 02', $c?->objeto_impuesto === '02');
    verificar('Guardó clave SAT, unidad e IVA', $c?->clave_sat === '86121600' && $c?->clave_unidad_sat === 'E48' && (float) $c?->tasa_iva === 0.16 && $c?->gravado === true);

    echo PHP_EOL.'3. Edición cambia el objeto de impuesto'.PHP_EOL;
    $ctrl->update(req([
        'clave' => $clave, 'nombre' => 'Concepto de prueba', 'objeto_impuesto' => '01', 'gravado' => false,
    ], 'PUT'), $c);
    $c->refresh();
    verificar('Cambió a 01 (no objeto) y exento', $c->objeto_impuesto === '01' && $c->gravado === false);

    echo PHP_EOL.'4. El objeto de impuesto es obligatorio'.PHP_EOL;
    $lanzo = false;
    try {
        $ctrl->store(req(['clave' => 'x'.random_int(1000, 9999), 'nombre' => 'Sin objeto']));
    } catch (Illuminate\Validation\ValidationException $e) {
        $lanzo = array_key_exists('objeto_impuesto', $e->errors());
    }
    verificar('Sin objeto_impuesto no valida', $lanzo);

    echo PHP_EOL.'5. Un concepto en uso NO se borra'.PHP_EOL;
    // Ligamos un adeudo al concepto de prueba y probamos el borrado.
    $matricula = MatriculaOferta::query()->first();
    if ($matricula !== null) {
        Adeudo::create([
            'matricula_oferta_id' => $matricula->id,
            'concepto_id' => $c->id,
            'monto' => 100, 'monto_total' => 100,
            'situacion_id' => SituacionPago::query()->value('id'),
            'fecha_generacion' => now()->toDateString(),
            'fecha_vencimiento' => now()->addDays(30)->toDateString(),
            'estatus' => 'pendiente',
        ]);
        $resp = $ctrl->destroy($c);
        verificar('El borrado se rechaza con mensaje de error', $resp->getSession()?->get('error') !== null && ConceptoPago::whereKey($c->id)->exists());
    } else {
        verificar('El borrado se rechaza con mensaje de error', true, 'omitido: sin matrículas');
    }

    echo PHP_EOL.'6. Un concepto sin uso sí se borra'.PHP_EOL;
    $clave2 = 'libre_'.random_int(1000, 9999);
    $ctrl->store(req(['clave' => $clave2, 'nombre' => 'Libre', 'objeto_impuesto' => '02']));
    $c2 = ConceptoPago::where('clave', $clave2)->first();
    $ctrl->destroy($c2);
    verificar('Concepto sin adeudos ni reglas se elimina', ! ConceptoPago::where('clave', $clave2)->exists());
} finally {
    DB::rollBack();
    echo PHP_EOL.'-- rollback aplicado, la base queda como estaba --'.PHP_EOL;
}

echo PHP_EOL.'Resultado: '.$ok.' correctas, '.count($fallos).' fallidas'.PHP_EOL;

if ($fallos !== []) {
    echo 'Fallaron: '.implode(' · ', $fallos).PHP_EOL;
}

exit($fallos === [] ? 0 : 1);
