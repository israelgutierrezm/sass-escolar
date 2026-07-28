<?php

/**
 * Módulo 2: al subir el CSD de una razón social, el SincronizadorEmisorFacturapi
 * crea la organización en Facturapi, sube el CSD y guarda organization_id +
 * llave de pruebas en el emisor — sin teclear nada. Con Http::fake. Contra la BD
 * real, con rollback.
 *
 * `php scripts/prueba-emisor-sincroniza-facturapi.php` desde la raíz.
 */

$raiz = dirname(__DIR__);

require $raiz.'/vendor/autoload.php';
$app = require $raiz.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Facturacion\FacturacionConfig;
use App\Models\Finanzas\EmisorFiscal;
use App\Services\Facturacion\SincronizadorEmisorFacturapi;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

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

DB::beginTransaction();

try {
    FacturacionConfig::actual()->forceFill([
        'activo' => true,
        'ambiente' => 'pruebas',
        'api_key_usuario' => 'sk_user_PRUEBA',
    ])->save();

    // Registro de las llamadas para verificar el ORDEN del flujo. Un solo fake
    // de closure con estado mutable: Http::fake fusiona stubs y gana el primero,
    // así que reusar un segundo fake no reemplaza a éste.
    $llamadas = [];
    $rechazar = false;
    Http::fake(function ($request) use (&$llamadas, &$rechazar) {
        $llamadas[] = $request->method().' '.parse_url($request->url(), PHP_URL_PATH);
        $url = $request->url();

        if ($rechazar) {
            return Http::response(['message' => 'El RFC del certificado no coincide'], 400);
        }

        if ($request->method() === 'POST' && str_ends_with($url, '/organizations')) {
            return Http::response(['id' => 'org_NUEVA'], 200);
        }
        if (str_ends_with($url, '/legal')) {
            return Http::response(['id' => 'org_NUEVA'], 200);
        }
        if (str_ends_with($url, '/certificate')) {
            return Http::response(['status' => 'ok'], 200);
        }
        if (str_ends_with($url, '/apikeys/test')) {
            return Http::response('"sk_test_ORGNUEVA"', 200, ['Content-Type' => 'application/json']);
        }

        return Http::response(['message' => 'sin stub'], 500);
    });

    echo '1. Emisor SIN organización: se crea, se sube el CSD y se capturan datos'.PHP_EOL;
    $emisor = EmisorFiscal::create([
        'rfc' => 'XAXX010101000',
        'razon_social' => 'ESCUELA DEMO SA DE CV',
        'nombre_comercial' => 'Escuela Demo',
        'regimen_fiscal' => '601',
        'cp' => '64000',
        'calle' => 'Av. Siempre Viva',
        'municipio' => 'Monterrey',
        'estado' => 'Nuevo León',
        'pais' => 'MEX',
    ]);
    verificar('Nace sin facturapi_id', $emisor->facturapi_id === null);

    SincronizadorEmisorFacturapi::paraLaEscuela()->sincronizar($emisor, 'BYTES_CER', 'BYTES_KEY', 'micontraseña');
    $emisor->refresh();

    verificar('Guardó el organization_id automáticamente', $emisor->facturapi_id === 'org_NUEVA', (string) $emisor->facturapi_id);
    verificar('Guardó la llave de pruebas de la organización', $emisor->facturapi_key_pruebas === 'sk_test_ORGNUEVA');
    verificar('Marcó la fecha de sincronización', $emisor->facturapi_sincronizado_en !== null);
    verificar('La llave de pruebas NO está en claro en la BD', ! str_contains(
        (string) DB::table('emisores_fiscales')->where('id', $emisor->id)->value('facturapi_key_pruebas'),
        'sk_test_ORGNUEVA'
    ));

    echo PHP_EOL.'2. El flujo llamó a Facturapi en el orden correcto'.PHP_EOL;
    verificar('POST /organizations primero', str_contains($llamadas[0] ?? '', 'POST') && str_ends_with($llamadas[0] ?? '', '/organizations'));
    verificar('Luego /legal, /certificate y /apikeys/test', str_ends_with($llamadas[1] ?? '', '/legal')
        && str_ends_with($llamadas[2] ?? '', '/certificate')
        && str_ends_with($llamadas[3] ?? '', '/apikeys/test'));

    echo PHP_EOL.'3. Emisor que YA tiene organización: no la vuelve a crear'.PHP_EOL;
    $llamadas = [];
    $emisor2 = EmisorFiscal::create([
        'rfc' => 'AAA010101AA1',
        'razon_social' => 'OTRA SA',
        'regimen_fiscal' => '601',
        'cp' => '64000',
        'facturapi_id' => 'org_YAEXISTE',
    ]);
    SincronizadorEmisorFacturapi::paraLaEscuela()->sincronizar($emisor2, 'CER', 'KEY', 'pass');
    $emisor2->refresh();
    verificar('Conserva su organization_id', $emisor2->facturapi_id === 'org_YAEXISTE');
    verificar('NO llamó a POST /organizations', ! collect($llamadas)->contains(fn ($l) => str_contains($l, 'POST') && str_ends_with($l, '/organizations')));
    verificar('Sí subió el CSD a su organización', collect($llamadas)->contains(fn ($l) => str_ends_with($l, '/organizations/org_YAEXISTE/certificate')));

    echo PHP_EOL.'4. Un rechazo de Facturapi sube como FacturapiRechazo'.PHP_EOL;
    $rechazar = true;
    $emisor3 = EmisorFiscal::create(['rfc' => 'BBB020202BB2', 'razon_social' => 'MAL SA', 'regimen_fiscal' => '601', 'cp' => '64000']);
    $rechazado = false;
    try {
        SincronizadorEmisorFacturapi::paraLaEscuela()->sincronizar($emisor3, 'CER', 'KEY', 'pass');
    } catch (\App\Services\Facturacion\FacturapiRechazo $e) {
        $rechazado = str_contains($e->getMessage(), 'RFC del certificado');
    }
    verificar('Propaga el rechazo con el mensaje de Facturapi', $rechazado);
    verificar('El emisor NO quedó marcado como sincronizado', $emisor3->fresh()->facturapi_sincronizado_en === null);
} finally {
    DB::rollBack();
    tenancy()->end();
}

echo PHP_EOL."Resultado: {$ok} correctas, ".count($fallos).' fallidas'.PHP_EOL;

if ($fallos !== []) {
    echo 'Fallidas: '.implode(', ', $fallos).PHP_EOL;
    exit(1);
}
