<?php

/**
 * Pasarelas de pago: guardado de credenciales por ambiente (cifradas), regla de
 * "no se activa sin datos completos", y que un campo en blanco no borra el
 * guardado. Contra la BD real, con rollback.
 *
 * `php scripts/prueba-pasarelas-pago.php` desde la raíz.
 */

$raiz = dirname(__DIR__);

require $raiz.'/vendor/autoload.php';
$app = require $raiz.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Controllers\PasarelaPagoController;
use App\Models\Finanzas\PasarelaPago;
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

function req(array $datos): Request
{
    $r = Request::create('/', 'PUT', $datos);
    app()->instance('request', $r);

    return $r;
}

DB::beginTransaction();

try {
    $ctrl = new PasarelaPagoController;

    echo '1. Activar sin credenciales completas → NO se enciende'.PHP_EOL;
    $ctrl->guardar(req([
        'ambiente' => 'pruebas',
        'activa' => true,
        'credenciales' => ['secret_key' => 'sk_test_123'], // falta publishable_key (requerido)
    ]), 'stripe');
    $stripe = PasarelaPago::para('stripe');
    verificar('Stripe quedó inactiva (le falta publishable_key)', $stripe->activa === false);
    verificar('Pero guardó la credencial capturada', ($stripe->credenciales_pruebas['secret_key'] ?? null) === 'sk_test_123');

    echo PHP_EOL.'2. Completar y activar → se enciende'.PHP_EOL;
    $ctrl->guardar(req([
        'ambiente' => 'pruebas',
        'activa' => true,
        'credenciales' => ['publishable_key' => 'pk_test_123'],
    ]), 'stripe');
    $stripe->refresh();
    verificar('Ahora sí quedó activa', $stripe->activa === true);
    verificar('Conservó la secret_key (no la borró el blanco)', ($stripe->credenciales_pruebas['secret_key'] ?? null) === 'sk_test_123');

    echo PHP_EOL.'3. Las credenciales NO están en claro en la BD'.PHP_EOL;
    $crudo = (string) DB::table('pasarelas_pago')->where('clave', 'stripe')->value('credenciales_pruebas');
    verificar('El JSON está cifrado', ! str_contains($crudo, 'sk_test_123') && ! str_contains($crudo, 'pk_test_123'));

    echo PHP_EOL.'4. Pruebas y producción son independientes'.PHP_EOL;
    verificar('Producción sigue incompleta', $stripe->completaEn('produccion') === false);
    $ctrl->guardar(req([
        'ambiente' => 'produccion',
        'activa' => true,
        'credenciales' => ['secret_key' => 'sk_live_1', 'publishable_key' => 'pk_live_1'],
    ]), 'stripe');
    $stripe->refresh();
    verificar('Producción quedó completa y activa', $stripe->completaEn('produccion') && $stripe->activa);
    verificar('Las de pruebas siguen intactas', ($stripe->credenciales_pruebas['secret_key'] ?? null) === 'sk_test_123');

    echo PHP_EOL.'5. Cada pasarela valida SUS campos requeridos'.PHP_EOL;
    // OpenPay requiere merchant_id + private_key + public_key.
    $ctrl->guardar(req([
        'ambiente' => 'pruebas',
        'activa' => true,
        'credenciales' => ['merchant_id' => 'm1', 'private_key' => 'sk1'], // falta public_key
    ]), 'openpay');
    verificar('OpenPay sin public_key no activa', PasarelaPago::para('openpay')->activa === false);

    // Mercado Pago requiere access_token + public_key.
    $ctrl->guardar(req([
        'ambiente' => 'pruebas',
        'activa' => true,
        'credenciales' => ['access_token' => 'AT-1', 'public_key' => 'PK-1'],
    ]), 'mercadopago');
    verificar('Mercado Pago completo sí activa', PasarelaPago::para('mercadopago')->activa === true);

    echo PHP_EOL.'6. Una clave de pasarela desconocida se rechaza'.PHP_EOL;
    $rechazado = false;
    try {
        $ctrl->guardar(req(['ambiente' => 'pruebas', 'activa' => false, 'credenciales' => []]), 'inventada');
    } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
        $rechazado = $e->getStatusCode() === 404;
    }
    verificar('Aborta con 404', $rechazado);

    echo PHP_EOL.'7. index() no expone valores de credenciales'.PHP_EOL;
    // Con el header X-Inertia, toResponse devuelve JSON (props).
    $reqI = req([]);
    $reqI->headers->set('X-Inertia', 'true');
    $props = $ctrl->index()->toResponse($reqI)->getData(true)['props'];
    $json = json_encode($props);
    verificar('No aparece ninguna credencial en el payload', ! str_contains($json, 'sk_test_123') && ! str_contains($json, 'sk_live_1'));
    verificar('Sí manda "puestos" (banderas), no valores', str_contains($json, 'puestos_pruebas'));
} finally {
    DB::rollBack();
    tenancy()->end();
}

echo PHP_EOL."Resultado: {$ok} correctas, ".count($fallos).' fallidas'.PHP_EOL;

if ($fallos !== []) {
    echo 'Fallidas: '.implode(', ', $fallos).PHP_EOL;
    exit(1);
}
