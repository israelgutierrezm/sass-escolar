<?php

/**
 * Emisión con Facturapi (FacturapiService + FacturapiPac) sobre el flujo
 * existente. Con Http::fake (no timbra de verdad). Contra la BD real, con
 * rollback.
 *
 * `php scripts/prueba-facturapi-emision.php` desde la raíz.
 */

$raiz = dirname(__DIR__);

require $raiz.'/vendor/autoload.php';
$app = require $raiz.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Admisiones\MatriculaOferta;
use App\Models\Facturacion\FacturacionConfig;
use App\Models\Finanzas\Factura;
use App\Services\Cfdi\FacturapiPac;
use App\Services\Cfdi\Pac;
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

function facturaDePrueba(): Factura
{
    $m = MatriculaOferta::query()->first();

    $f = Factura::create([
        'matricula_oferta_id' => $m?->id,
        'emisor_razon_social' => 'Instituto Demo SA',
        'emisor_rfc' => 'IDE010101AB1',
        'emisor_regimen_fiscal' => '601',
        'emisor_cp' => '64000',
        'receptor_rfc' => 'XAXX010101000',
        'receptor_razon_social' => 'PUBLICO EN GENERAL',
        'receptor_uso_cfdi' => 'G03',
        'receptor_regimen_fiscal' => '616',
        'receptor_cp' => '64000',
        'forma_pago_sat' => '03',
        'metodo_pago_sat' => 'PUE',
        'subtotal' => 100, 'iva' => 16, 'total' => 116,
        'estatus' => Factura::ESTATUS_BORRADOR,
    ]);

    $f->conceptos()->create([
        'clave_sat' => '86121600', 'clave_unidad_sat' => 'E48', 'descripcion' => 'Colegiatura',
        'cantidad' => 1, 'valor_unitario' => 100, 'importe' => 100, 'iva' => 16, 'objeto_impuesto' => '02',
    ]);

    return $f->fresh('conceptos');
}

DB::beginTransaction();

try {
    // Activa Facturapi con una llave cualquiera (Http::fake intercepta la red).
    FacturacionConfig::actual()->forceFill([
        'activo' => true, 'ambiente' => 'pruebas', 'api_key_pruebas' => 'sk_test_LLAVE',
    ])->save();

    // Un solo fake con closure + estado mutable: evita el merge de Http::fake
    // (donde el primer stub por-URL le gana al siguiente).
    $modoPost = 'ok';
    Http::fake(function ($request) use (&$modoPost) {
        $url = $request->url();

        if (str_ends_with($url, '/xml')) {
            return Http::response('<cfdi/>', 200);
        }
        if (str_ends_with($url, '/pdf')) {
            return Http::response('PDF-BYTES', 200);
        }
        if ($request->method() === 'DELETE') {
            return Http::response(['status' => 'canceled'], 200);
        }
        if (str_ends_with($url, '/v2/invoices')) {
            return match ($modoPost) {
                'rechazo' => Http::response(['message' => 'El RFC del receptor no existe', 'code' => 'CFDI40102'], 400),
                'error5xx' => Http::response(['message' => 'boom'], 503),
                default => Http::response(['id' => 'inv_ABC', 'uuid' => 'FAKE-UUID-0001'], 201),
            };
        }

        return Http::response([], 200);
    });

    echo '1. Con Facturapi activo, el PAC resuelto es FacturapiPac'.PHP_EOL;
    verificar('El binding entrega FacturapiPac', app(Pac::class) instanceof FacturapiPac);

    echo PHP_EOL.'2. Timbrado exitoso mapea folio, XML/PDF y facturapi_id'.PHP_EOL;
    $f = facturaDePrueba();
    $res = app(FacturapiPac::class)->timbrar($f);
    verificar('Devuelve éxito con el folio fiscal', $res->exito && $res->uuid === 'FAKE-UUID-0001');
    verificar('Trae el XML y el PDF', $res->xml === '<cfdi/>' && $res->pdf === 'PDF-BYTES');
    verificar('Guardó el facturapi_id en la factura', $f->facturapi_id === 'inv_ABC');

    echo PHP_EOL.'3. Un rechazo (4xx) NO lanza: vuelve como rechazado'.PHP_EOL;
    $modoPost = 'rechazo';
    $res2 = app(FacturapiPac::class)->timbrar(facturaDePrueba());
    verificar('Rechazado con el mensaje del SAT', ! $res2->exito && str_contains((string) $res2->error, 'RFC del receptor'));
    verificar('Trae el código del rechazo', $res2->codigo === 'CFDI40102');

    echo PHP_EOL.'4. Una falla 5xx sí se propaga (para reintentar)'.PHP_EOL;
    $modoPost = 'error5xx';
    $lanzo = false;
    try {
        app(FacturapiPac::class)->timbrar(facturaDePrueba());
    } catch (\RuntimeException $e) {
        $lanzo = ! ($e instanceof App\Services\Facturacion\FacturapiRechazo);
    }
    verificar('Un 503 se propaga como excepción de transporte', $lanzo);

    echo PHP_EOL.'5. Cancelación con Facturapi'.PHP_EOL;
    $f3 = facturaDePrueba();
    $f3->update(['uuid' => 'UUID-CANCELA', 'facturapi_id' => 'inv_XYZ', 'estatus' => Factura::ESTATUS_TIMBRADA]);
    $resC = app(FacturapiPac::class)->cancelar($f3->fresh(), Factura::MOTIVO_SIN_RELACION);
    verificar('La cancelación fue exitosa', $resC->exito, (string) ($resC->error ?? 'ok'));

    echo PHP_EOL.'6. Sin facturapi_id no se puede cancelar'.PHP_EOL;
    $f4 = facturaDePrueba();
    $resSinId = app(FacturapiPac::class)->cancelar($f4, Factura::MOTIVO_SIN_RELACION);
    verificar('Rechaza cancelar sin id de Facturapi', ! $resSinId->exito);
} finally {
    DB::rollBack();
    echo PHP_EOL.'-- rollback aplicado, la base queda como estaba --'.PHP_EOL;
}

echo PHP_EOL.'Resultado: '.$ok.' correctas, '.count($fallos).' fallidas'.PHP_EOL;

if ($fallos !== []) {
    echo 'Fallaron: '.implode(' · ', $fallos).PHP_EOL;
}

exit($fallos === [] ? 0 : 1);
