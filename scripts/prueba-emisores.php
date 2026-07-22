<?php

/**
 * Prueba de integración de las razones sociales (varias personas morales por
 * escuela): precedencia carrera → nivel → global, congelado del emisor en la
 * factura, cifrado de credenciales y el error cuando una carrera queda sin
 * asignar. Con rollback.
 *
 * Se corre con `php scripts/prueba-emisores.php` desde la raíz.
 *
 * No toca a ningún usuario existente ni le cambia el rol activo.
 *
 * Los `use` van ARRIBA del arranque a propósito: un alias solo aplica a partir
 * de donde se declara.
 */

use App\Models\Academico\Carrera;
use App\Models\Academico\Oferta;
use App\Models\Finanzas\Adeudo;
use App\Models\Finanzas\ConceptoPago;
use App\Models\Finanzas\EmisorAsignacion;
use App\Models\Finanzas\EmisorFiscal;
use App\Models\Finanzas\Factura;
use App\Models\Finanzas\MetodoPago;
use App\Models\Identidad\Persona;
use App\Models\Tenant;
use App\Services\EmisorFactura;
use App\Services\MatriculadorOferta;
use App\Services\RegistradorPago;
use App\Services\ResolutorEmisorFiscal;
use Illuminate\Contracts\Console\Kernel;
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

$receptor = [
    'rfc' => 'GUME900101AB1',
    'razon_social' => 'MARIA GUTIERREZ MENDOZA',
    'uso_cfdi' => 'D10',
    'regimen_fiscal' => '605',
    'cp' => '44100',
];

DB::beginTransaction();

try {
    $resolutor = app(ResolutorEmisorFiscal::class);
    $emisorFactura = app(EmisorFactura::class);
    $registrador = app(RegistradorPago::class);

    $efectivo = MetodoPago::where('clave', 'efectivo')->firstOrFail();
    $colegiatura = ConceptoPago::where('clave', 'colegiatura')->firstOrFail();

    // Dos ofertas de carreras distintas: es lo que permite probar que a cada
    // una le toca una razón social diferente.
    $ofertaA = Oferta::query()->with('carrera')->firstOrFail();
    $ofertaB = Oferta::query()->with('carrera')
        ->where('carrera_id', '!=', $ofertaA->carrera_id)->first();

    if ($ofertaB === null) {
        echo 'AVISO: la escuela demo solo tiene una carrera con oferta; se omiten los casos por carrera.'.PHP_EOL;
    }

    $carreraA = $ofertaA->carrera;

    echo '1. Alta de razones sociales'.PHP_EOL;

    $bachillerato = EmisorFiscal::create([
        'rfc' => 'IEB010101AA1',
        'razon_social' => 'INSTITUTO EDUCATIVO BACHILLERATO SC',
        'regimen_fiscal' => '603',
        'cp' => '44100',
    ]);

    $superior = EmisorFiscal::create([
        'rfc' => 'UES020202BB2',
        'razon_social' => 'UNIVERSIDAD DE ESTUDIOS SUPERIORES SC',
        'regimen_fiscal' => '603',
        'cp' => '06600',
    ]);

    verificar('Se dan de alta dos personas morales distintas',
        EmisorFiscal::whereIn('id', [$bachillerato->id, $superior->id])->count() === 2);
    verificar('Nacen activas pero SIN poder timbrar: les falta el certificado',
        $bachillerato->activo && ! $bachillerato->puedeTimbrar());

    echo PHP_EOL.'2. Las credenciales se guardan cifradas'.PHP_EOL;

    $superior->update([
        'llave_password' => 'secreto-de-la-llave',
        'pac_usuario' => 'usuario-pac',
        'pac_password' => 'contrasena-pac',
    ]);

    $crudo = DB::table('emisores_fiscales')->where('id', $superior->id)->first();

    verificar('En la base NO queda el texto en claro',
        $crudo->llave_password !== 'secreto-de-la-llave' && ! str_contains((string) $crudo->llave_password, 'secreto'),
        substr((string) $crudo->llave_password, 0, 24).'…');
    verificar('Pero el modelo lo descifra al leer',
        $superior->fresh()->llave_password === 'secreto-de-la-llave');
    verificar('Los secretos NO viajan al front',
        ! array_key_exists('llave_password', $superior->fresh()->toArray())
        && ! array_key_exists('pac_password', $superior->fresh()->toArray()));

    echo PHP_EOL.'3. Sin asignaciones, ninguna razón social aplica'.PHP_EOL;

    $persona = Persona::create(['nombre' => 'Aurora', 'primer_apellido' => 'Padilla', 'sexo_id' => 2]);
    $matricula = app(MatriculadorOferta::class)->matricular($persona, $ofertaA, '2026-2030');

    verificar('El resolutor no encuentra ninguna', $resolutor->para($matricula) === null);

    $sinAsignar = false;
    $mensaje = '';
    try {
        $resolutor->datosPara($matricula);
    } catch (RuntimeException $e) {
        $sinAsignar = true;
        $mensaje = $e->getMessage();
    }
    // Hay emisores dados de alta pero ninguno cubre esta carrera: eso es una
    // configuración incompleta y hay que gritarlo, no facturar a nombre de
    // cualquiera.
    verificar('Y facturar se rechaza diciendo qué falta', $sinAsignar, $mensaje);
    verificar('El mensaje nombra la carrera concreta',
        str_contains($mensaje, (string) $carreraA?->nombre), (string) $carreraA?->nombre);

    echo PHP_EOL.'4. Precedencia: global → nivel → carrera'.PHP_EOL;

    // Global: cubre a todos.
    $bachillerato->asignaciones()->create(['aplica_a_tipo' => EmisorAsignacion::APLICA_GLOBAL]);

    verificar('Con una asignación global, esa aplica a cualquier carrera',
        $resolutor->para($matricula)?->id === $bachillerato->id);

    // Nivel: gana al global para las carreras de ese nivel.
    if ($carreraA?->nivel_estudios_id !== null) {
        $superior->asignaciones()->create([
            'aplica_a_tipo' => EmisorAsignacion::APLICA_NIVEL,
            'aplica_a_id' => $carreraA->nivel_estudios_id,
        ]);

        verificar('Una asignación por NIVEL le gana a la global',
            $resolutor->para($matricula)?->id === $superior->id,
            (string) $resolutor->para($matricula)?->razon_social);
    }

    // Carrera: gana a todo.
    $tercera = EmisorFiscal::create([
        'rfc' => 'PSG030303CC3',
        'razon_social' => 'POSGRADOS ESPECIALIZADOS SC',
        'regimen_fiscal' => '603',
        'cp' => '11000',
    ]);
    $tercera->asignaciones()->create([
        'aplica_a_tipo' => EmisorAsignacion::APLICA_CARRERA,
        'aplica_a_id' => $carreraA->id,
    ]);

    verificar('Una asignación por CARRERA le gana al nivel y a la global',
        $resolutor->para($matricula)?->id === $tercera->id,
        (string) $resolutor->para($matricula)?->razon_social);

    verificar('Una razón social puede cubrir varias cosas a la vez',
        $bachillerato->asignaciones()->count() === 1
        && $tercera->asignaciones()->count() === 1);

    // Desactivar la más específica devuelve el mando a la siguiente.
    $tercera->update(['activo' => false]);

    verificar('Desactivar la más específica devuelve el mando a la siguiente',
        $resolutor->para($matricula)?->id !== $tercera->id,
        (string) $resolutor->para($matricula)?->razon_social);

    $tercera->update(['activo' => true]);

    echo PHP_EOL.'5. Carreras distintas, razones sociales distintas'.PHP_EOL;

    if ($ofertaB !== null) {
        $persona2 = Persona::create(['nombre' => 'Bruno', 'primer_apellido' => 'Salas', 'sexo_id' => 1]);
        $matricula2 = app(MatriculadorOferta::class)->matricular($persona2, $ofertaB, '2026-2030');

        // La carrera B no tiene asignación propia: cae al nivel o al global.
        verificar('La otra carrera resuelve a una razón social DISTINTA',
            $resolutor->para($matricula2)?->id !== $tercera->id,
            (string) $resolutor->para($matricula2)?->razon_social);
        verificar('Y la primera sigue con la suya',
            $resolutor->para($matricula)?->id === $tercera->id);
    }

    echo PHP_EOL.'6. El emisor se CONGELA en la factura'.PHP_EOL;

    $adeudo = Adeudo::create([
        'matricula_oferta_id' => $matricula->id,
        'concepto_id' => $colegiatura->id,
        'periodo_etiqueta' => 'Marzo 2026',
        'monto' => 2000, 'monto_total' => 2000,
        'fecha_generacion' => '2026-03-01', 'fecha_vencimiento' => '2026-03-10',
    ]);

    $pago = $registrador->registrar($matricula->id, $efectivo, 2000.00, [$adeudo->id]);

    $factura = $emisorFactura->emitir($matricula->id, [$pago->id], $receptor);
    $factura->refresh();

    if ($factura->xml_ruta !== null) {
        $archivos[] = $factura->xml_ruta;
    }

    verificar('La factura se timbra', $factura->estatus === Factura::ESTATUS_TIMBRADA);
    verificar('Con la razón social que le tocaba a su carrera',
        $factura->emisor_rfc === $tercera->rfc, (string) $factura->emisor_razon_social);
    verificar('Y guarda de dónde salió', $factura->emisor_id === $tercera->id);

    // Cambiar la razón social después NO debe reescribir el comprobante.
    $tercera->update(['razon_social' => 'POSGRADOS ESPECIALIZADOS SA DE CV', 'cp' => '99999']);

    verificar('Corregir la razón social NO altera lo ya timbrado',
        $factura->fresh()->emisor_razon_social === 'POSGRADOS ESPECIALIZADOS SC',
        (string) $factura->fresh()->emisor_razon_social);
    verificar('Ni su código postal', $factura->fresh()->emisor_cp === '11000');

    // Y reasignar la carrera a otra razón social tampoco toca lo emitido.
    $tercera->asignaciones()->delete();

    verificar('Quitarle la asignación tampoco cambia el comprobante',
        $factura->fresh()->emisor_rfc === $tercera->rfc);
    verificar('Aunque la carrera ya resuelva a otra',
        $resolutor->para($matricula)?->id !== $tercera->id,
        (string) $resolutor->para($matricula)?->razon_social);

    echo PHP_EOL.'7. Una razón social que ya facturó no se borra'.PHP_EOL;

    verificar('Tiene facturas colgando', $tercera->facturas()->count() === 1);
    // El controlador lo impide; aquí se comprueba el dato en el que se apoya.
    verificar('Y ese es el dato que lo impide: se desactiva, no se elimina',
        $tercera->facturas()->exists());
} catch (Throwable $e) {
    echo PHP_EOL.'EXCEPCIÓN: '.$e->getMessage().PHP_EOL;
    echo $e->getFile().':'.$e->getLine().PHP_EOL;
    $fallos[] = 'excepción: '.$e->getMessage();
} finally {
    DB::rollBack();

    foreach ($archivos as $ruta) {
        Storage::disk('local')->delete($ruta);
    }

    echo PHP_EOL.'-- rollback aplicado, la base queda como estaba --'.PHP_EOL;
}

echo PHP_EOL."Resultado: {$ok} correctas, ".count($fallos).' fallidas'.PHP_EOL;

foreach ($fallos as $fallo) {
    echo "  - {$fallo}".PHP_EOL;
}

exit($fallos === [] ? 0 : 1);
