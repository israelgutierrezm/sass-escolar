<?php

/**
 * Autoservicio de factura (R06, rebanada 3): el alumno o su familia SOLICITA, la
 * escuela emite. Contra la BD real, con rollback. El timbrado corre en cola
 * `sync` para ejercer el motor de verdad, y los XML del PAC falso se limpian.
 *
 * `php scripts/prueba-solicitud-factura.php` desde la raíz.
 */

use App\Configuracion\Ajustes;
use App\Configuracion\CatalogoAjustes;
use App\Exceptions\AvisoParaElUsuario;
use App\Http\Controllers\SolicitudFacturaController;
use App\Models\Academico\Oferta;
use App\Models\Finanzas\Adeudo;
use App\Models\Finanzas\ConceptoPago;
use App\Models\Finanzas\EmisorAsignacion;
use App\Models\Finanzas\EmisorFiscal;
use App\Models\Finanzas\Factura;
use App\Models\Finanzas\MetodoPago;
use App\Models\Finanzas\SolicitudFactura;
use App\Models\Identidad\Persona;
use App\Models\Identidad\Rol;
use App\Models\Identidad\Usuario;
use App\Models\Tenant;
use App\Services\GestorSolicitudFactura;
use App\Services\MatriculadorOferta;
use App\Services\RegistradorPago;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
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

/** Un receptor válido (catálogo del SAT). */
$receptor = [
    'rfc' => 'GUME900101AB1', 'razon_social' => 'MARIA GUTIERREZ MENDOZA',
    'uso_cfdi' => 'G03', 'regimen_fiscal' => '616', 'cp' => '44100', 'correo' => 'mg@correo.mx',
];

DB::beginTransaction();

try {
    $gestor = app(GestorSolicitudFactura::class);
    $registrador = app(RegistradorPago::class);
    $efectivo = MetodoPago::where('clave', 'efectivo')->firstOrFail();
    $concepto = ConceptoPago::where('clave', 'colegiatura')->firstOrFail();

    // Sin razón social asignada, facturar se rechaza a propósito: basta la global.
    $emisorEscuela = EmisorFiscal::create([
        'rfc' => 'AAA010101AAA', 'razon_social' => 'ESCUELA DEMO SC',
        'regimen_fiscal' => '603', 'cp' => '44100',
    ]);
    $emisorEscuela->asignaciones()->create(['aplica_a_tipo' => EmisorAsignacion::APLICA_GLOBAL]);

    $persona = Persona::create(['nombre' => 'María', 'primer_apellido' => 'Gutiérrez', 'sexo_id' => 2]);
    $matricula = app(MatriculadorOferta::class)->matricular($persona, Oferta::firstOrFail(), '2026-2030');

    $pago = function (float $monto) use ($matricula, $concepto, $registrador, $efectivo) {
        $adeudo = Adeudo::create([
            'matricula_oferta_id' => $matricula->id, 'concepto_id' => $concepto->id,
            'monto' => $monto, 'monto_total' => $monto,
            'fecha_generacion' => '2026-03-01', 'fecha_vencimiento' => '2026-03-10',
        ]);

        return $registrador->registrar($matricula, $efectivo, $monto, [$adeudo->id]);
    };

    $pago1 = $pago(2000);
    $pago2 = $pago(1500);
    $pago3 = $pago(1000);
    $pago4 = $pago(800);
    $pago5 = $pago(600);

    echo '1. Solicitar: nace pendiente, con los pagos y el receptor congelados'.PHP_EOL;
    $sol = $gestor->solicitar($matricula, [$pago1->id], $receptor, null);
    verificar('Queda PENDIENTE', $sol->estado === SolicitudFactura::PENDIENTE);
    verificar('Guarda los pagos pedidos', $sol->pago_ids === [$pago1->id]);
    verificar('Congela el receptor', $sol->receptor_rfc === 'GUME900101AB1' && $sol->receptor_regimen_fiscal === '616');
    verificar('Y el correo de entrega, aparte', $sol->receptor_correo === 'mg@correo.mx');

    echo PHP_EOL.'2. Las guardas del portal'.PHP_EOL;
    $vacio = false;
    try { $gestor->solicitar($matricula, [], $receptor, null); } catch (AvisoParaElUsuario) { $vacio = true; }
    verificar('Sin elegir operaciones, se rehúsa', $vacio);

    $dup = false;
    try { $gestor->solicitar($matricula, [$pago1->id], $receptor, null); } catch (AvisoParaElUsuario) { $dup = true; }
    verificar('El mismo pago en una solicitud pendiente NO se pide dos veces', $dup);

    echo PHP_EOL.'3. Emitir: nace el CFDI reusando el motor'.PHP_EOL;
    $factura = $gestor->emitir($sol);
    if ($factura->xml_ruta !== null) { $archivos[] = $factura->xml_ruta; }
    $sol->refresh();
    verificar('La solicitud queda EMITIDA', $sol->estado === SolicitudFactura::EMITIDA);
    verificar('Y ligada a su factura', $sol->factura_id === $factura->id);
    verificar('La factura se timbró con folio', $factura->fresh()->uuid !== null);
    verificar('Con revisor y momento', $sol->revisado_en !== null);

    $doble = false;
    try { $gestor->emitir($sol); } catch (AvisoParaElUsuario) { $doble = true; }
    verificar('Emitir dos veces la misma solicitud se rehúsa', $doble);

    // Con el pago ya facturado, pedirlo otra vez cae en «ya no facturable».
    $noFacturable = false;
    try { $gestor->solicitar($matricula, [$pago1->id], $receptor, null); } catch (AvisoParaElUsuario) { $noFacturable = true; }
    verificar('Un pago ya facturado no se puede volver a solicitar', $noFacturable);

    echo PHP_EOL.'4. Rechazar: con motivo, y sin factura'.PHP_EOL;
    $sol2 = $gestor->solicitar($matricula, [$pago2->id], $receptor, null);
    $gestor->rechazar($sol2, 'El RFC no coincide con tu constancia de situación fiscal.');
    $sol2->refresh();
    verificar('Queda RECHAZADA', $sol2->estado === SolicitudFactura::RECHAZADA);
    verificar('Con el motivo guardado', str_contains((string) $sol2->motivo_rechazo, 'constancia'));
    verificar('Y sin factura', $sol2->factura_id === null);

    $rechDoble = false;
    try { $gestor->rechazar($sol2, 'otra vez'); } catch (AvisoParaElUsuario) { $rechDoble = true; }
    verificar('No se rechaza algo ya atendido', $rechDoble);

    echo PHP_EOL.'5. El gate de configuración, en el controlador'.PHP_EOL;
    $ctrl = app(SolicitudFacturaController::class);
    $admin = usuarioDePrueba('director_general');
    auth()->login($admin);

    $peticionSolicitar = function (int $pagoId) use ($matricula, $receptor) {
        $r = Request::create('/', 'POST', ['pago_ids' => [$pagoId]] + $receptor);
        app()->instance('request', $r);

        return $r;
    };

    // Apagado: la ruta no existe (404), aunque el usuario pueda ver la cuenta.
    app(Ajustes::class)->guardar([CatalogoAjustes::FACTURA_AUTOSERVICIO_SOLICITUD => false]);
    $apagado = false;
    try {
        $ctrl->solicitar($peticionSolicitar($pago3->id), $matricula);
    } catch (AvisoParaElUsuario $e) {
        $apagado = $e->getStatusCode() === 404;
    }
    verificar('Con el canal APAGADO, solicitar responde 404', $apagado);
    verificar('Y no se creó ninguna solicitud del pago3',
        SolicitudFactura::where('matricula_oferta_id', $matricula->id)
            ->whereJsonContains('pago_ids', $pago3->id)->doesntExist());

    // Encendido: se crea.
    app(Ajustes::class)->guardar([CatalogoAjustes::FACTURA_AUTOSERVICIO_SOLICITUD => true]);
    $ctrl->solicitar($peticionSolicitar($pago3->id), $matricula);
    verificar('Con el canal ENCENDIDO, la solicitud se crea',
        SolicitudFactura::where('matricula_oferta_id', $matricula->id)
            ->whereJsonContains('pago_ids', $pago3->id)->where('estado', SolicitudFactura::PENDIENTE)->exists());

    echo PHP_EOL.'6. El estado de cuenta arma las props del autoservicio sin romper'.PHP_EOL;
    $finanzas = app(App\Http\Controllers\FinanzasController::class);
    $peticionCuenta = Request::create('/', 'GET');
    $peticionCuenta->headers->set('X-Inertia', 'true');
    $peticionCuenta->headers->set('X-Inertia-Version', '');
    app()->instance('request', $peticionCuenta);
    $propsCuenta = json_decode($finanzas->cuenta($peticionCuenta, $matricula)->toResponse($peticionCuenta)->getContent(), true)['props'];
    verificar('Trae la lista de solicitudes (historial, siempre)',
        is_array($propsCuenta['solicitudesFactura'] ?? null) && count($propsCuenta['solicitudesFactura']) >= 1);
    verificar('Y el modo del autoservicio', array_key_exists('facturaModo', $propsCuenta));

    echo PHP_EOL.'7. Generar al momento: nace el CFDI, y queda como solicitud emitida'.PHP_EOL;
    $facturaGen = $gestor->generarDirecto($matricula, [$pago4->id], $receptor, null);
    if ($facturaGen->xml_ruta !== null) { $archivos[] = $facturaGen->xml_ruta; }
    verificar('La factura se timbró al instante', $facturaGen->fresh()->uuid !== null);
    $constancia = SolicitudFactura::where('factura_id', $facturaGen->id)->first();
    verificar('Deja constancia como solicitud EMITIDA', $constancia !== null && $constancia->estado === SolicitudFactura::EMITIDA);
    verificar('El pago generado ya no es facturable',
        ! app(App\Services\EmisorFactura::class)->facturables($matricula->id)->pluck('id')->contains($pago4->id));

    $genVacio = false;
    try { $gestor->generarDirecto($matricula, [], $receptor, null); } catch (AvisoParaElUsuario) { $genVacio = true; }
    verificar('Generar sin operaciones se rehúsa', $genVacio);

    echo PHP_EOL.'8. El gate de GENERAR, aparte del de solicitar'.PHP_EOL;
    $peticionGenerar = function (int $pagoId) use ($matricula, $receptor) {
        $r = Request::create('/', 'POST', ['pago_ids' => [$pagoId]] + $receptor);
        app()->instance('request', $r);

        return $r;
    };

    app(Ajustes::class)->guardar([CatalogoAjustes::FACTURA_AUTOSERVICIO_GENERAR => false]);
    $genApagado = false;
    try {
        $ctrl->generar($peticionGenerar($pago5->id), $matricula);
    } catch (AvisoParaElUsuario $e) {
        $genApagado = $e->getStatusCode() === 404;
    }
    verificar('Con GENERAR apagado, responde 404', $genApagado);
    verificar('Y no nació ninguna factura del pago5',
        SolicitudFactura::where('matricula_oferta_id', $matricula->id)
            ->whereJsonContains('pago_ids', $pago5->id)->doesntExist());

    app(Ajustes::class)->guardar([CatalogoAjustes::FACTURA_AUTOSERVICIO_GENERAR => true]);
    $ctrl->generar($peticionGenerar($pago5->id), $matricula);
    $gen5 = SolicitudFactura::where('matricula_oferta_id', $matricula->id)
        ->whereJsonContains('pago_ids', $pago5->id)->first();
    if (($f5 = $gen5?->factura) && $f5->xml_ruta !== null) { $archivos[] = $f5->xml_ruta; }
    verificar('Con GENERAR encendido, la factura nace emitida', $gen5 !== null && $gen5->estado === SolicitudFactura::EMITIDA && $gen5->factura_id !== null);
} catch (Throwable $e) {
    echo PHP_EOL.'EXCEPCIÓN: '.$e->getMessage().PHP_EOL.$e->getFile().':'.$e->getLine().PHP_EOL;
    $fallos[] = 'excepción: '.$e->getMessage();
} finally {
    DB::rollBack();
    foreach ($archivos as $ruta) { Storage::disk('local')->delete($ruta); }
    echo PHP_EOL.'-- rollback aplicado, la base queda como estaba --'.PHP_EOL;
}

/** Un usuario con el rol dado como activo, para probar el gate del controlador. */
function usuarioDePrueba(string $rol): Usuario
{
    $persona = Persona::create([
        'nombre' => 'Prueba', 'primer_apellido' => 'Solicitud',
        'segundo_apellido' => (string) random_int(1000, 9999), 'sexo_id' => 1,
    ]);

    $cuenta = Usuario::create([
        'persona_id' => $persona->id,
        'usuario' => 'prueba_sol_'.random_int(100000, 999999),
        'email' => 'prueba_sol_'.random_int(100000, 999999).'@ejemplo.mx',
        'password' => Hash::make('secreto12345'),
        'rol_activo_id' => Rol::where('name', $rol)->firstOrFail()->id,
    ]);

    $cuenta->persona->asignacionesRol()->create(['rol_id' => $cuenta->rol_activo_id, 'activo' => true]);

    return $cuenta->fresh(['persona', 'rolActivo']);
}

echo PHP_EOL."Resultado: {$ok} correctas, ".count($fallos).' fallidas'.PHP_EOL;
foreach ($fallos as $f) { echo "  - {$f}".PHP_EOL; }

exit($fallos === [] ? 0 : 1);
