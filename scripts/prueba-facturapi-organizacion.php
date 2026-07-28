<?php

/**
 * Módulo 1 de "alta de razón social crea la organización en Facturapi":
 * los métodos de administración del FacturapiService (crear organización, subir
 * CSD, pedir la llave de pruebas) usan la SECRET ADMIN KEY y hablan con los
 * endpoints correctos. Con Http::fake, sin llamadas reales. Contra la BD real,
 * con rollback.
 *
 * `php scripts/prueba-facturapi-organizacion.php` desde la raíz.
 */

$raiz = dirname(__DIR__);

require $raiz.'/vendor/autoload.php';
$app = require $raiz.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Facturacion\FacturacionConfig;
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

DB::beginTransaction();

try {
    // Config con la Admin Key puesta (cifrada) — sin ella el servicio revienta.
    $config = FacturacionConfig::actual();
    $config->forceFill([
        'api_key_usuario' => 'sk_user_PRUEBA1234',
        'api_key_pruebas' => 'sk_test_PRUEBA',
        'ambiente' => 'pruebas',
    ])->save();

    echo '1. La Admin Key se guarda cifrada y se relee en claro'.PHP_EOL;
    $recargada = FacturacionConfig::actual()->fresh();
    verificar('apiKeyUsuario() la devuelve en claro', $recargada->apiKeyUsuario() === 'sk_user_PRUEBA1234');
    $crudo = DB::table('facturacion_config')->where('id', $recargada->id)->value('api_key_usuario');
    verificar('En la BD NO está en claro (cifrada)', $crudo !== null && ! str_contains((string) $crudo, 'sk_user_PRUEBA1234'));
    verificar('enmascarar() solo muestra los últimos 4', FacturacionConfig::enmascarar($recargada->apiKeyUsuario()) === '••••••••1234');

    $servicio = FacturapiService::paraLaEscuela();

    // Un solo fake de closure con estado mutable: Http::fake fusiona stubs y gana
    // el PRIMERO, así que reusar la misma URL con otra respuesta no funciona. El
    // closure decide según `$modo`, que cada paso ajusta antes de llamar.
    $modo = 'ok';
    Http::fake(function ($request) use (&$modo) {
        $url = $request->url();

        if (str_ends_with($url, '/organizations') && $modo === 'rechazo') {
            return Http::response(['message' => 'RFC inválido'], 400);
        }
        if (str_ends_with($url, '/organizations')) {
            return Http::response(['id' => 'org_ABC123', 'legal' => ['name' => 'ESCUELA DEMO SA']], 200);
        }
        if (str_contains($url, '/certificates')) {
            return Http::response(['status' => 'ok'], 200);
        }
        if (str_contains($url, '/test-api-key')) {
            return Http::response('"sk_test_ORGABC"', 200, ['Content-Type' => 'application/json']);
        }

        return Http::response(['message' => 'sin stub'], 500);
    });

    echo PHP_EOL.'2. crearOrganizacion → POST /organizations con la Admin Key'.PHP_EOL;
    $org = $servicio->crearOrganizacion(['name' => 'ESCUELA DEMO SA']);
    verificar('Devuelve el id de la organización', ($org['id'] ?? null) === 'org_ABC123', (string) ($org['id'] ?? '—'));
    Http::assertSent(function ($request) {
        // Basic Auth con la Admin Key (base64 de "sk_user_PRUEBA1234:")
        $auth = $request->header('Authorization')[0] ?? '';
        $esperado = 'Basic '.base64_encode('sk_user_PRUEBA1234:');

        return $request->method() === 'POST'
            && str_ends_with($request->url(), '/v2/organizations')
            && $auth === $esperado;
    });

    echo PHP_EOL.'3. subirCertificado → PUT /organizations/{id}/certificates (multipart)'.PHP_EOL;
    $res = $servicio->subirCertificado('org_ABC123', 'CONTENIDO_CER', 'CONTENIDO_KEY', 'micontraseña');
    verificar('Responde ok', ($res['status'] ?? null) === 'ok');
    Http::assertSent(function ($request) {
        $esCert = str_ends_with($request->url(), '/organizations/org_ABC123/certificates');
        $esMultipart = str_contains($request->header('Content-Type')[0] ?? '', 'multipart/form-data');

        return $request->method() === 'PUT' && $esCert && $esMultipart;
    });

    echo PHP_EOL.'4. obtenerLlavePruebas → GET /organizations/{id}/test-api-key'.PHP_EOL;
    $llave = $servicio->obtenerLlavePruebas('org_ABC123');
    verificar('Devuelve la llave de pruebas', $llave === 'sk_test_ORGABC', $llave);

    echo PHP_EOL.'5. Un rechazo de Facturapi (4xx) se traduce a FacturapiRechazo'.PHP_EOL;
    $modo = 'rechazo';
    $rechazado = false;
    try {
        $servicio->crearOrganizacion(['name' => 'MAL']);
    } catch (\App\Services\Facturacion\FacturapiRechazo $e) {
        $rechazado = $e->getMessage() === 'RFC inválido';
    }
    verificar('Lanza FacturapiRechazo con el mensaje de Facturapi', $rechazado);

    echo PHP_EOL.'6. Sin Admin Key configurada, revienta con mensaje claro'.PHP_EOL;
    $config->forceFill(['api_key_usuario' => null])->save();
    $sinLlave = false;
    try {
        FacturapiService::paraLaEscuela()->crearOrganizacion(['name' => 'X']);
    } catch (\App\Services\Facturacion\FacturapiRechazo $e) {
        $sinLlave = str_contains($e->getMessage(), 'Secret Admin Key');
    }
    verificar('Explica que falta la Secret Admin Key', $sinLlave);
} finally {
    DB::rollBack();
    tenancy()->end();
}

echo PHP_EOL."Resultado: {$ok} correctas, ".count($fallos).' fallidas'.PHP_EOL;

if ($fallos !== []) {
    echo 'Fallidas: '.implode(', ', $fallos).PHP_EOL;
    exit(1);
}
