<?php

/**
 * API de la app móvil: el ALUMNO paga y factura su PROPIA cuenta —el estado de
 * cuenta trae el autoservicio y el pago; solicitar/generar factura, iniciar el
 * cobro y subir comprobante viven en el trait compartido con la familia—. Contra
 * la BD real, con rollback; pasarela `fake` y disco `local` falso.
 *
 * `php scripts/prueba-api-alumno-finanzas.php` desde la raíz.
 *
 * ── Qué se vigila ─────────────────────────────────────────────────────────
 *  1. El estado de cuenta del alumno trae factura (autoservicio/solicitudes/
 *     facturas), cuentas para transferencia, factura_modo y el bloque de pago.
 *  2. Solicitar/generar factura, iniciar pago y subir comprobante operan sobre
 *     SU matrícula, por el mismo trait y servicios que la familia.
 *  3. La cartera cierra a quién: la matrícula de OTRA persona → 403 (ALCANCE_PROPIO).
 */

use App\Configuracion\Ajustes;
use App\Configuracion\CatalogoAjustes;
use App\Http\Controllers\Api\AlumnoApiController;
use App\Http\Middleware\Api\OperarComoFaceta;
use App\Models\Academico\Oferta;
use App\Models\Finanzas\Adeudo;
use App\Models\Finanzas\ComprobantePago;
use App\Models\Finanzas\ConceptoPago;
use App\Models\Finanzas\EmisorAsignacion;
use App\Models\Finanzas\EmisorFiscal;
use App\Models\Finanzas\IntencionCobro;
use App\Models\Finanzas\MetodoPago;
use App\Models\Finanzas\PasarelaPago;
use App\Models\Finanzas\SolicitudFactura;
use App\Models\Identidad\Persona;
use App\Models\Identidad\Rol;
use App\Models\Identidad\Usuario;
use App\Models\Tenant;
use App\Services\MatriculadorOferta;
use App\Services\RegistradorPago;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
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
config(['queue.default' => 'sync', 'pagos.modo' => 'fake']);
Storage::fake('local');

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

function comoAlumno(Usuario $usuario, array $datos = [], string $metodo = 'GET', array $archivos = []): Request
{
    $p = Request::create('/', $metodo, $datos, [], $archivos);
    $p->setUserResolver(fn () => $usuario);

    return $p;
}

$receptor = [
    'rfc' => 'GUME900101AB1', 'razon_social' => 'JUAN ALUMNO', 'uso_cfdi' => 'G03',
    'regimen_fiscal' => '616', 'cp' => '44100', 'correo' => 'j@correo.mx',
];

DB::beginTransaction();

try {
    EmisorFiscal::create(['rfc' => 'AAA010101AAA', 'razon_social' => 'ESCUELA DEMO SC', 'regimen_fiscal' => '603', 'cp' => '44100'])
        ->asignaciones()->create(['aplica_a_tipo' => EmisorAsignacion::APLICA_GLOBAL]);
    PasarelaPago::updateOrCreate(['clave' => 'mercadopago'], ['activa' => true, 'ambiente' => 'pruebas']);

    $registrador = app(RegistradorPago::class);
    $efectivo = MetodoPago::where('clave', 'efectivo')->firstOrFail();
    $concepto = ConceptoPago::where('clave', 'colegiatura')->firstOrFail();
    $matricular = app(MatriculadorOferta::class);

    // El ALUMNO: su persona ES la de la cuenta (matricula.persona_id == usuario.persona_id).
    $persona = Persona::create(['nombre' => 'Juan', 'primer_apellido' => 'Alumno', 'sexo_id' => 1]);
    $matricula = $matricular->matricular($persona, Oferta::firstOrFail(), '2026-2030');

    $cargo = fn (float $monto) => Adeudo::create([
        'matricula_oferta_id' => $matricula->id, 'concepto_id' => $concepto->id,
        'monto' => $monto, 'monto_total' => $monto, 'fecha_generacion' => '2026-03-01', 'fecha_vencimiento' => '2026-03-10',
    ]);
    $cobrado = $cargo(2000);
    $pago = $registrador->registrar($matricula, $efectivo, 2000, [$cobrado->id]); // facturable
    $abierto1 = $cargo(1500);
    $abierto2 = $cargo(900);

    $facetaAlumno = Rol::where('name', 'alumno')->firstOrFail();
    $facetaAlumno->givePermissionTo(['solicitar-factura', 'generar-mi-factura', 'ver-adeudos', 'ver-mis-cursos']);
    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

    // Matricular aprovisiona una cuenta a la persona (usuarios.persona_id es
    // único), así que se reusa esa; sólo se le fija la faceta como rol activo.
    $usuario = Usuario::where('persona_id', $persona->id)->first()
        ?? Usuario::create([
            'persona_id' => $persona->id,
            'usuario' => 'alu_fin_'.random_int(100000, 999999),
            'email' => 'alu_fin_'.random_int(100000, 999999).'@ejemplo.mx',
            'password' => Hash::make('secreto12345'),
            'rol_activo_id' => $facetaAlumno->id,
        ]);
    $usuario->forceFill(['rol_activo_id' => $facetaAlumno->id])->save();
    $usuario->persona->asignacionesRol()->firstOrCreate(['rol_id' => $facetaAlumno->id], ['activo' => true]);
    $usuario = $usuario->fresh(['persona', 'rolActivo']);

    // Otra persona con su matrícula, para el 403 de cartera ajena.
    $ajenaP = Persona::create(['nombre' => 'Otra', 'primer_apellido' => 'Persona', 'sexo_id' => 2]);
    $ajenaM = $matricular->matricular($ajenaP, Oferta::firstOrFail(), '2026-2030');

    $ajustes = app(Ajustes::class);
    $ajustes->guardar([CatalogoAjustes::FACTURA_AUTOSERVICIO_SOLICITUD => true, CatalogoAjustes::FACTURA_AUTOSERVICIO_GENERAR => true]);

    auth()->login($usuario);
    (new OperarComoFaceta)->handle(comoAlumno($usuario), fn ($r) => new Response('', 200), 'alumno');

    $ctrl = app(AlumnoApiController::class);

    echo PHP_EOL.'1. El estado de cuenta trae factura y pago'.PHP_EOL;

    $api = json_decode($ctrl->estadoCuenta(comoAlumno($usuario, ['matricula' => $matricula->id]))->getContent(), true);
    verificar('Trae la cuenta', ($api['cuenta'] ?? null) !== null);
    verificar('Con factura_modo «generar» (ambos canales)', ($api['factura_modo'] ?? null) === 'generar');
    verificar('El autoservicio ofrece el pago cobrado', collect($api['factura_autoservicio']['pagos'] ?? [])->pluck('id')->contains($pago->id));
    verificar('Trae solicitudes, facturas y cuentas', array_key_exists('solicitudes_factura', $api) && array_key_exists('facturas', $api) && array_key_exists('cuentas_bancarias', $api));
    verificar('Y el bloque de pago con la pasarela', collect($api['pago']['pasarelas'] ?? [])->pluck('clave')->contains('mercadopago'));

    echo PHP_EOL.'2. Solicitar y generar factura de su cuenta'.PHP_EOL;

    $ctrl->solicitarFactura(comoAlumno($usuario, ['pago_ids' => [$pago->id]] + $receptor, 'POST'), $matricula);
    verificar('Queda una solicitud pendiente', SolicitudFactura::where('matricula_oferta_id', $matricula->id)->where('estado', SolicitudFactura::PENDIENTE)->exists());

    echo PHP_EOL.'3. Iniciar el cobro de sus cargos abiertos'.PHP_EOL;

    $resp = json_decode($ctrl->iniciarPago(comoAlumno($usuario, ['pasarela' => 'mercadopago', 'adeudo_ids' => [$abierto1->id]], 'POST'), $matricula)->getContent(), true);
    verificar('Devuelve la URL de checkout', ! empty($resp['url']));
    verificar('Y nace la intención de su matrícula', IntencionCobro::where('matricula_oferta_id', $matricula->id)->exists());

    echo PHP_EOL.'4. Subir el comprobante de una transferencia'.PHP_EOL;

    $archivo = UploadedFile::fake()->create('deposito.pdf', 100, 'application/pdf');
    $ctrl->subirComprobante(comoAlumno($usuario, ['monto' => 900, 'fecha_transferencia' => now()->subDay()->toDateString(), 'adeudo_ids' => [$abierto2->id]], 'POST', ['archivo' => $archivo]), $matricula);
    verificar('Queda un comprobante PENDIENTE', ComprobantePago::where('matricula_oferta_id', $matricula->id)->where('estado', ComprobantePago::PENDIENTE)->exists());

    echo PHP_EOL.'5. La cartera cierra a quién (ALCANCE_PROPIO)'.PHP_EOL;

    verificar('Iniciar pago sobre la matrícula de otra persona → 403',
        fallo(fn () => $ctrl->iniciarPago(comoAlumno($usuario, ['pasarela' => 'mercadopago', 'adeudo_ids' => [$abierto1->id]], 'POST'), $ajenaM)) === 403);
    verificar('Solicitar factura sobre una cuenta ajena → 403',
        fallo(fn () => $ctrl->solicitarFactura(comoAlumno($usuario, ['pago_ids' => [$pago->id]] + $receptor, 'POST'), $ajenaM)) === 403);
    verificar('Subir comprobante sobre una cuenta ajena → 403',
        fallo(fn () => $ctrl->subirComprobante(comoAlumno($usuario, ['monto' => 100, 'fecha_transferencia' => now()->subDay()->toDateString()], 'POST', ['archivo' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')]), $ajenaM)) === 403);

    echo PHP_EOL.'6. El canal lo abre la escuela'.PHP_EOL;

    $ajustes->guardar([CatalogoAjustes::FACTURA_AUTOSERVICIO_SOLICITUD => false]);
    verificar('Con SOLICITUD apagado, solicitar → 404',
        fallo(fn () => $ctrl->solicitarFactura(comoAlumno($usuario, ['pago_ids' => [$pago->id]] + $receptor, 'POST'), $matricula)) === 404);
} catch (Throwable $e) {
    verificar('La suite corrió hasta el final sin morir', false,
        get_class($e).': '.$e->getMessage().' @ '.basename($e->getFile()).':'.$e->getLine());
} finally {
    DB::rollBack();
}

echo PHP_EOL.'Resultado: '.($verificaciones - $fallidas).' correctas, '.$fallidas.' fallidas'.PHP_EOL;

exit($fallidas === 0 ? 0 : 1);
