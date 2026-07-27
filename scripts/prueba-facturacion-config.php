<?php

/**
 * Configuración de facturación: singleton, cifrado y enmascarado de llaves,
 * guardado con bitácora (sin llaves completas), y prueba de conexión con el
 * servicio (Http::fake). Contra la BD real, con rollback.
 *
 * `php scripts/prueba-facturacion-config.php` desde la raíz.
 */

$raiz = dirname(__DIR__);

require $raiz.'/vendor/autoload.php';
$app = require $raiz.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Controllers\FacturacionConfigController;
use App\Models\Facturacion\FacturacionConfig;
use App\Models\Facturacion\FacturacionEvento;
use App\Models\Identidad\Persona;
use App\Models\Identidad\Usuario;
use App\Services\Facturacion\FacturapiService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
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

function usuario(): Usuario
{
    $p = Persona::create(['nombre' => 'Fact', 'primer_apellido' => 'Admin', 'segundo_apellido' => (string) random_int(1000, 9999)]);

    return Usuario::create([
        'persona_id' => $p->id, 'usuario' => 'fact'.random_int(10000, 99999),
        'email' => 'fact'.random_int(10000, 99999).'@ejemplo.mx', 'password' => Hash::make('secreto12345'),
    ]);
}

function req(array $datos, Usuario $u): Request
{
    $r = Request::create('/', 'PUT', $datos);
    app()->instance('request', $r);
    $r->setUserResolver(fn () => $u);

    return $r;
}

DB::beginTransaction();

try {
    $admin = usuario();
    $ctrl = new FacturacionConfigController;

    echo '1. Config única (singleton)'.PHP_EOL;
    $c1 = FacturacionConfig::actual();
    $c2 = FacturacionConfig::actual();
    verificar('actual() devuelve la misma fila', $c1->id === $c2->id);
    verificar('Nace en pruebas y desactivada', $c1->ambiente === 'pruebas' && $c1->activo === false);

    echo PHP_EOL.'2. Guardar: llaves cifradas y bitácora sin llaves'.PHP_EOL;
    $ctrl->guardar(req([
        'activo' => true, 'ambiente' => 'pruebas',
        'api_key_pruebas' => 'sk_test_SUPERSECRETO1234',
        'organizacion_id' => 'org_123', 'moneda_default' => 'MXN',
        'uso_cfdi_default' => 'D10', 'objeto_impuesto_default' => '02',
    ], $admin));

    $config = FacturacionConfig::actual()->fresh();
    verificar('La llave se lee descifrada por el modelo', $config->api_key_pruebas === 'sk_test_SUPERSECRETO1234');

    $rawLlave = DB::table('facturacion_config')->where('id', $config->id)->value('api_key_pruebas');
    verificar('En la BD la llave está CIFRADA (no en claro)', $rawLlave !== null && ! str_contains((string) $rawLlave, 'SUPERSECRETO'));
    verificar('Enmascarar muestra solo los últimos 4', FacturacionConfig::enmascarar('sk_test_SUPERSECRETO1234') === '••••••••1234');

    $ev = FacturacionEvento::where('tipo', 'config_guardada')->latest('id')->first();
    verificar('Se registró config_guardada por el usuario', $ev !== null && $ev->usuario_id === $admin->id);
    verificar('La bitácora NO contiene la llave completa',
        $ev !== null && ! str_contains(json_encode($ev->detalle) ?: '', 'SUPERSECRETO'));
    verificar('Se registró la activación del módulo', FacturacionEvento::where('tipo', 'modulo_activado')->exists());

    echo PHP_EOL.'3. Dejar la llave en blanco NO la borra'.PHP_EOL;
    $ctrl->guardar(req(['activo' => true, 'ambiente' => 'produccion', 'api_key_pruebas' => '', 'serie_default' => 'A'], $admin));
    $config = FacturacionConfig::actual()->fresh();
    verificar('La llave de pruebas se conservó', $config->api_key_pruebas === 'sk_test_SUPERSECRETO1234');
    verificar('El cambio de ambiente quedó en bitácora', FacturacionEvento::where('tipo', 'ambiente_cambiado')->exists());

    echo PHP_EOL.'4. Probar conexión (Http::fake)'.PHP_EOL;
    // Sin llave del ambiente activo (produccion) → error sin tocar la red.
    $sinLlave = (new FacturapiService(FacturacionConfig::actual()->fresh()))->probarConexion();
    verificar('Sin llave del ambiente → error claro', $sinLlave['ok'] === false && str_contains($sinLlave['mensaje'], 'Falta la API key'));

    // Una secuencia global evita que un fake por-URL «gane» al siguiente:
    // 200 (éxito), 401 (fallida), 200 (para el controlador del paso 5).
    Http::fakeSequence()
        ->push([['id' => 'x']], 200)
        ->push(['message' => 'Unauthorized'], 401)
        ->push([], 200);

    FacturacionConfig::actual()->forceFill(['ambiente' => 'pruebas'])->save();
    $exito = FacturapiService::paraLaEscuela()->probarConexion();
    verificar('200 simulado → conexión exitosa', $exito['ok'] === true);

    $err = FacturapiService::paraLaEscuela()->probarConexion();
    verificar('401 simulado → conexión fallida', $err['ok'] === false && $err['mensaje'] !== '');

    echo PHP_EOL.'5. El controlador de probar asienta estado y bitácora'.PHP_EOL;
    $ctrl->probar(req([], $admin), FacturapiService::paraLaEscuela());
    $config = FacturacionConfig::actual()->fresh();
    verificar('Guardó estado ok y fecha de prueba', $config->conexion_estado === 'ok' && $config->conexion_probada_en !== null);
    verificar('Registró conexion_probada', FacturacionEvento::where('tipo', 'conexion_probada')->exists());
} finally {
    DB::rollBack();
    echo PHP_EOL.'-- rollback aplicado, la base queda como estaba --'.PHP_EOL;
}

echo PHP_EOL.'Resultado: '.$ok.' correctas, '.count($fallos).' fallidas'.PHP_EOL;

if ($fallos !== []) {
    echo 'Fallaron: '.implode(' · ', $fallos).PHP_EOL;
}

exit($fallos === [] ? 0 : 1);
