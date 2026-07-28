<?php

/**
 * Módulo 3: en PRUEBAS, el timbrado usa la llave de la organización del emisor
 * (cada razón social timbra con la suya); si el emisor no tiene llave, cae a la
 * de la configuración general; en producción usa la de la config. Con Http::fake
 * se inspecciona con QUÉ llave (Basic Auth) salió cada request. Contra la BD
 * real, con rollback.
 *
 * `php scripts/prueba-timbrado-por-emisor.php` desde la raíz.
 */

$raiz = dirname(__DIR__);

require $raiz.'/vendor/autoload.php';
$app = require $raiz.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Facturacion\FacturacionConfig;
use App\Models\Finanzas\EmisorFiscal;
use App\Services\Facturacion\FacturapiService;
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

/** La llave (usuario del Basic Auth) con la que salió el último request. */
function llaveDelRequest($request): string
{
    $auth = $request->header('Authorization')[0] ?? '';
    $decodificado = base64_decode(substr($auth, strlen('Basic ')), true) ?: '';

    return rtrim($decodificado, ':');
}

DB::beginTransaction();

try {
    FacturacionConfig::actual()->forceFill([
        'activo' => true,
        'ambiente' => 'pruebas',
        'api_key_pruebas' => 'sk_test_CONFIG',
        'api_key_produccion' => 'sk_live_CONFIG',
    ])->save();

    $llaveUsada = null;
    Http::fake(function ($request) use (&$llaveUsada) {
        $llaveUsada = llaveDelRequest($request);

        return Http::response(['id' => 'inv_1', 'uuid' => 'UUID-1'], 200);
    });

    $emisorConLlave = EmisorFiscal::create([
        'rfc' => 'AAA010101AA1', 'razon_social' => 'CON LLAVE SA', 'regimen_fiscal' => '601', 'cp' => '64000',
        'facturapi_id' => 'org_1', 'facturapi_key_pruebas' => 'sk_test_ORG1',
    ]);
    $emisorSinLlave = EmisorFiscal::create([
        'rfc' => 'BBB020202BB2', 'razon_social' => 'SIN LLAVE SA', 'regimen_fiscal' => '601', 'cp' => '64000',
    ]);

    echo '1. PRUEBAS + emisor con llave → timbra con la llave del emisor'.PHP_EOL;
    FacturapiService::paraEmisor($emisorConLlave)->emitirFactura(['x' => 1]);
    verificar('Usó la llave de la organización del emisor', $llaveUsada === 'sk_test_ORG1', (string) $llaveUsada);

    echo PHP_EOL.'2. PRUEBAS + emisor sin llave → cae a la llave de la config'.PHP_EOL;
    FacturapiService::paraEmisor($emisorSinLlave)->emitirFactura(['x' => 1]);
    verificar('Usó la llave de pruebas de la config', $llaveUsada === 'sk_test_CONFIG', (string) $llaveUsada);

    echo PHP_EOL.'3. Sin emisor → llave de la config'.PHP_EOL;
    FacturapiService::paraEmisor(null)->emitirFactura(['x' => 1]);
    verificar('Usó la llave de pruebas de la config', $llaveUsada === 'sk_test_CONFIG', (string) $llaveUsada);

    echo PHP_EOL.'4. PRODUCCIÓN → siempre la llave de producción de la config'.PHP_EOL;
    FacturacionConfig::actual()->forceFill(['ambiente' => 'produccion'])->save();
    FacturapiService::paraEmisor($emisorConLlave)->emitirFactura(['x' => 1]);
    verificar('Ignora la llave de pruebas del emisor en producción', $llaveUsada === 'sk_live_CONFIG', (string) $llaveUsada);
} finally {
    DB::rollBack();
    tenancy()->end();
}

echo PHP_EOL."Resultado: {$ok} correctas, ".count($fallos).' fallidas'.PHP_EOL;

if ($fallos !== []) {
    echo 'Fallidas: '.implode(', ', $fallos).PHP_EOL;
    exit(1);
}
