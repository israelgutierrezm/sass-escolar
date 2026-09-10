<?php

/**
 * API de la app móvil: el autoservicio de FACTURA de la familia —leer qué se
 * puede facturar, SOLICITAR, GENERAR al momento y descargar el CFDI—. Contra la
 * BD real, con rollback; el timbrado corre en cola `sync` (PAC falso) y los
 * archivos van a un disco `local` FALSO, así que no se toca nada.
 *
 * `php scripts/prueba-api-factura-familia.php` desde la raíz.
 *
 * ── Qué se vigila ─────────────────────────────────────────────────────────
 *  1. Una sola verdad: la lectura sale de `AutoservicioFactura` y las
 *     escrituras de `GestorSolicitudFactura` —los MISMOS servicios que la web—.
 *  2. La cartera decide de quién es la cuenta con `VeLaCarteraDelAlumno`: la
 *     matrícula de un hijo NO vinculado (o sin `puede_ver_finanzas`) → 403.
 *  3. El canal lo abre la escuela: apagado, solicitar/generar → 404 (no 403).
 *  4. `factura_modo` respeta las tres capas y la precedencia (generar > solicitar).
 *  5. La descarga sólo entrega lo TIMBRADO, y sólo de la cuenta que se alcanza.
 */

use App\Configuracion\Ajustes;
use App\Configuracion\CatalogoAjustes;
use App\Http\Controllers\Api\PadreApiController;
use App\Http\Middleware\Api\OperarComoFaceta;
use App\Models\Academico\Oferta;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\Finanzas\Adeudo;
use App\Models\Finanzas\ConceptoPago;
use App\Models\Finanzas\EmisorAsignacion;
use App\Models\Finanzas\EmisorFiscal;
use App\Models\Finanzas\MetodoPago;
use App\Models\Finanzas\SolicitudFactura;
use App\Models\Identidad\Parentesco;
use App\Models\Identidad\Persona;
use App\Models\Identidad\Rol;
use App\Models\Identidad\TutorAlumno;
use App\Models\Identidad\Usuario;
use App\Models\Tenant;
use App\Services\MatriculadorOferta;
use App\Services\RegistradorPago;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

tenancy()->initialize(Tenant::find('demo'));
config(['queue.default' => 'sync']); // el timbrado corre de verdad, con el PAC falso
Storage::fake('local');              // el XML/PDF del PAC no tocan el disco real

$verificaciones = 0;
$fallidas = 0;

function verificar(string $que, bool $ok, string $detalle = ''): void
{
    global $verificaciones, $fallidas;
    $verificaciones++;
    $ok || $fallidas++;
    echo ($ok ? "  \033[32mOK\033[39m   " : "  \033[31mFALLA\033[39m ").$que.($detalle !== '' ? "  [{$detalle}]" : '').PHP_EOL;
}

function fallo(callable $accion): ?int
{
    try {
        $accion();
    } catch (ValidationException $e) {
        return $e->status;
    } catch (HttpException $e) {
        return $e->getStatusCode();
    }

    return null;
}

/** Una petición de la familia: el usuario ya pinchado como faceta padre. */
function comoFamilia(Usuario $usuario, array $datos = [], string $metodo = 'GET'): Request
{
    $p = Request::create('/', $metodo, $datos);
    $p->setUserResolver(fn () => $usuario);

    return $p;
}

/** Un receptor válido (catálogo del SAT). */
$receptor = [
    'rfc' => 'GUME900101AB1', 'razon_social' => 'MARIA GUTIERREZ MENDOZA',
    'uso_cfdi' => 'G03', 'regimen_fiscal' => '616', 'cp' => '44100', 'correo' => 'mg@correo.mx',
];

DB::beginTransaction();

try {
    // ── Escenario ────────────────────────────────────────────────────────────
    // Una razón social global, para que emitir no se rehúse por falta de emisor.
    EmisorFiscal::create([
        'rfc' => 'AAA010101AAA', 'razon_social' => 'ESCUELA DEMO SC',
        'regimen_fiscal' => '603', 'cp' => '44100',
    ])->asignaciones()->create(['aplica_a_tipo' => EmisorAsignacion::APLICA_GLOBAL]);

    $registrador = app(RegistradorPago::class);
    $efectivo = MetodoPago::where('clave', 'efectivo')->firstOrFail();
    $concepto = ConceptoPago::where('clave', 'colegiatura')->firstOrFail();

    $hijo = Persona::create(['nombre' => 'Hijo', 'primer_apellido' => 'Factura', 'sexo_id' => 1]);
    $matricula = app(MatriculadorOferta::class)->matricular($hijo, Oferta::firstOrFail(), '2026-2030');

    $cobrar = function (float $monto) use ($matricula, $concepto, $registrador, $efectivo) {
        $adeudo = Adeudo::create([
            'matricula_oferta_id' => $matricula->id, 'concepto_id' => $concepto->id,
            'monto' => $monto, 'monto_total' => $monto,
            'fecha_generacion' => '2026-03-01', 'fecha_vencimiento' => '2026-03-10',
        ]);

        return $registrador->registrar($matricula, $efectivo, $monto, [$adeudo->id]);
    };

    $pago1 = $cobrar(2000);
    $pago2 = $cobrar(1500);
    $pago3 = $cobrar(1000);
    $pago4 = $cobrar(800);

    // El tutor con cuenta, faceta padre, vinculado y con acceso financiero.
    $facetaPadre = Rol::where('name', 'padre_familia')->firstOrFail();
    $facetaPadre->givePermissionTo(['solicitar-factura', 'generar-mi-factura', 'ver-mis-hijos']);
    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

    $tutor = Persona::create(['nombre' => 'Tutora', 'primer_apellido' => 'Factura', 'sexo_id' => 2]);
    $usuario = Usuario::create([
        'persona_id' => $tutor->id,
        'usuario' => 'tutor_fac_'.random_int(100000, 999999),
        'email' => 'tutor_fac_'.random_int(100000, 999999).'@ejemplo.mx',
        'password' => Hash::make('secreto12345'),
        'rol_activo_id' => $facetaPadre->id,
    ]);
    $usuario->persona->asignacionesRol()->create(['rol_id' => $facetaPadre->id, 'activo' => true]);
    $usuario = $usuario->fresh(['persona', 'rolActivo']);

    TutorAlumno::create([
        'tutor_persona_id' => $tutor->id,
        'alumno_persona_id' => $hijo->id,
        'parentesco_id' => Parentesco::query()->value('id'),
        'puede_ver_academico' => true,
        'puede_ver_finanzas' => true,
    ]);

    $ajustes = app(Ajustes::class);
    $ajustes->guardar([
        CatalogoAjustes::FACTURA_AUTOSERVICIO_SOLICITUD => true,
        CatalogoAjustes::FACTURA_AUTOSERVICIO_GENERAR => true,
    ]);

    // Pinchar la faceta, como el middleware de la API.
    auth()->login($usuario);
    (new OperarComoFaceta)->handle(comoFamilia($usuario), fn ($r) => new Response('', 200), 'padre_familia');

    $ctrl = app(PadreApiController::class);

    // Sanidad: el permiso de faceta está, o el resto probaría por la razón mala.
    verificar('El tutor tiene el permiso de solicitar factura', $usuario->can('solicitar-factura'));

    echo PHP_EOL.'1. La lectura del hijo trae el autoservicio de factura'.PHP_EOL;

    $api = json_decode($ctrl->hijo(comoFamilia($usuario), $hijo->fresh())->getContent(), true);
    verificar('Con ambos canales, factura_modo es «generar» (manda sobre solicitar)', ($api['factura_modo'] ?? null) === 'generar');
    $fin = $api['finanzas'][0] ?? [];
    verificar('Cada matrícula trae facturas, solicitudes y autoservicio',
        array_key_exists('facturas', $fin) && array_key_exists('solicitudes_factura', $fin) && ($fin['factura_autoservicio'] ?? null) !== null);
    $idsFacturables = array_column($fin['factura_autoservicio']['pagos'] ?? [], 'id');
    verificar('El autoservicio ofrece los pagos cobrados sin facturar',
        in_array($pago1->id, $idsFacturables, true) && in_array($pago2->id, $idsFacturables, true));
    verificar('Y el catálogo del SAT para el receptor',
        ! empty($fin['factura_autoservicio']['catalogos']['usos_cfdi']) && ! empty($fin['factura_autoservicio']['catalogos']['regimenes']));

    echo PHP_EOL.'2. Solicitar: nace una solicitud pendiente por el servicio compartido'.PHP_EOL;

    $ctrl->solicitarFactura(comoFamilia($usuario, ['pago_ids' => [$pago1->id]] + $receptor, 'POST'), $matricula);
    $sol = SolicitudFactura::where('matricula_oferta_id', $matricula->id)->whereJsonContains('pago_ids', $pago1->id)->first();
    verificar('Queda una solicitud PENDIENTE con ese pago', $sol !== null && $sol->estado === SolicitudFactura::PENDIENTE);
    verificar('Con el receptor congelado', $sol?->receptor_rfc === 'GUME900101AB1');

    echo PHP_EOL.'3. El canal lo abre la escuela: apagado → 404 (no 403)'.PHP_EOL;

    $ajustes->guardar([CatalogoAjustes::FACTURA_AUTOSERVICIO_SOLICITUD => false]);
    verificar('Con SOLICITUD apagado, solicitar responde 404',
        fallo(fn () => $ctrl->solicitarFactura(comoFamilia($usuario, ['pago_ids' => [$pago2->id]] + $receptor, 'POST'), $matricula)) === 404);
    verificar('Y no se creó ninguna solicitud del pago2',
        SolicitudFactura::where('matricula_oferta_id', $matricula->id)->whereJsonContains('pago_ids', $pago2->id)->doesntExist());
    $ajustes->guardar([CatalogoAjustes::FACTURA_AUTOSERVICIO_SOLICITUD => true]);

    echo PHP_EOL.'4. La cartera cierra a quién: una matrícula ajena → 403'.PHP_EOL;

    $ajenoPersona = Persona::create(['nombre' => 'Ajeno', 'primer_apellido' => 'Factura', 'sexo_id' => 1]);
    $ajenaMatricula = app(MatriculadorOferta::class)->matricular($ajenoPersona, Oferta::firstOrFail(), '2026-2030');
    verificar('Solicitar sobre la cuenta de un alumno NO vinculado → 403',
        fallo(fn () => $ctrl->solicitarFactura(comoFamilia($usuario, ['pago_ids' => [$pago2->id]] + $receptor, 'POST'), $ajenaMatricula)) === 403);

    echo PHP_EOL.'5. Generar al momento: nace el CFDI, con su constancia'.PHP_EOL;

    $ctrl->generarFactura(comoFamilia($usuario, ['pago_ids' => [$pago3->id]] + $receptor, 'POST'), $matricula);
    $gen = SolicitudFactura::where('matricula_oferta_id', $matricula->id)->whereJsonContains('pago_ids', $pago3->id)->first();
    verificar('Deja constancia como solicitud EMITIDA con su factura', $gen !== null && $gen->estado === SolicitudFactura::EMITIDA && $gen->factura_id !== null);
    verificar('La factura se timbró (folio del PAC)', $gen?->factura?->uuid !== null);

    $ajustes->guardar([CatalogoAjustes::FACTURA_AUTOSERVICIO_GENERAR => false]);
    verificar('Con GENERAR apagado, generar responde 404',
        fallo(fn () => $ctrl->generarFactura(comoFamilia($usuario, ['pago_ids' => [$pago4->id]] + $receptor, 'POST'), $matricula)) === 404);
    $ajustes->guardar([CatalogoAjustes::FACTURA_AUTOSERVICIO_GENERAR => true]);

    echo PHP_EOL.'6. Descargar el CFDI: sólo lo timbrado, y sólo de su cuenta'.PHP_EOL;

    $resp = $ctrl->descargarCfdi(comoFamilia($usuario), $gen->fresh(), 'xml');
    verificar('El dueño descarga el XML del CFDI timbrado (200)', $resp->getStatusCode() === 200);
    verificar('Un tipo que no es xml/pdf → 404',
        fallo(fn () => $ctrl->descargarCfdi(comoFamilia($usuario), $gen->fresh(), 'zip')) === 404);

    // Una solicitud sobre la matrícula ajena: el padre no alcanza esa cuenta.
    $solAjena = SolicitudFactura::create([
        'matricula_oferta_id' => $ajenaMatricula->id,
        'pago_ids' => [$pago4->id],
        'receptor_rfc' => 'GUME900101AB1', 'receptor_razon_social' => 'X',
        'receptor_uso_cfdi' => 'G03', 'receptor_regimen_fiscal' => '616', 'receptor_cp' => '44100',
        'estado' => SolicitudFactura::EMITIDA, 'factura_id' => $gen->factura_id,
    ]);
    verificar('Descargar el CFDI de una solicitud de cuenta ajena → 403',
        fallo(fn () => $ctrl->descargarCfdi(comoFamilia($usuario), $solAjena, 'xml')) === 403);

    echo PHP_EOL.'7. factura_modo respeta el vínculo y los canales'.PHP_EOL;

    // Sólo SOLICITUD encendido → modo «solicitar».
    $ajustes->guardar([CatalogoAjustes::FACTURA_AUTOSERVICIO_GENERAR => false]);
    $api2 = json_decode($ctrl->hijo(comoFamilia($usuario), $hijo->fresh())->getContent(), true);
    verificar('Con sólo SOLICITUD, factura_modo es «solicitar»', ($api2['factura_modo'] ?? null) === 'solicitar');
    $ajustes->guardar([CatalogoAjustes::FACTURA_AUTOSERVICIO_GENERAR => true]);

    // Sin acceso financiero, no hay autoservicio aunque los canales estén abiertos.
    TutorAlumno::where('tutor_persona_id', $tutor->id)->where('alumno_persona_id', $hijo->id)
        ->update(['puede_ver_finanzas' => false]);
    $api3 = json_decode($ctrl->hijo(comoFamilia($usuario), $hijo->fresh())->getContent(), true);
    verificar('Sin acceso financiero, factura_modo es null y no viaja finanzas',
        $api3['factura_modo'] === null && $api3['finanzas'] === null);
} catch (Throwable $e) {
    verificar('La suite corrió hasta el final sin morir', false,
        get_class($e).': '.$e->getMessage().' @ '.basename($e->getFile()).':'.$e->getLine());
} finally {
    DB::rollBack();
}

echo PHP_EOL.'Resultado: '.($verificaciones - $fallidas).' correctas, '.$fallidas.' fallidas'.PHP_EOL;

exit($fallidas === 0 ? 0 : 1);
