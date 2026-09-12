<?php

/**
 * API de la app móvil: pagar en línea del hijo —iniciar el cobro con una
 * pasarela y subir el comprobante de una transferencia—. Contra la BD real, con
 * rollback; la pasarela en modo `fake` y el archivo en un disco `local` falso.
 *
 * `php scripts/prueba-api-pagos-familia.php` desde la raíz.
 *
 * ── Qué se vigila ─────────────────────────────────────────────────────────
 *  1. La lectura del hijo trae el pago (pasarelas, mínimo, pago_total) y las
 *     cuentas para transferencia, atados a lo financiero del vínculo.
 *  2. Iniciar reusa `CobroEnLinea::iniciar` y devuelve una URL; subir el
 *     comprobante reusa `RegistroDeComprobante` y nace PENDIENTE.
 *  3. La cartera cierra a quién: una matrícula ajena → 403 en las dos acciones.
 *  4. El filtro de cargos al titular: un adeudo ajeno no entra al comprobante.
 */

use App\Configuracion\Ajustes;
use App\Configuracion\CatalogoAjustes;
use App\Http\Controllers\Api\PadreApiController;
use App\Http\Middleware\Api\OperarComoFaceta;
use App\Models\Academico\Oferta;
use App\Models\Finanzas\Adeudo;
use App\Models\Finanzas\ComprobantePago;
use App\Models\Finanzas\ConceptoPago;
use App\Models\Finanzas\IntencionCobro;
use App\Models\Finanzas\PasarelaPago;
use App\Models\Identidad\Parentesco;
use App\Models\Identidad\Persona;
use App\Models\Identidad\Rol;
use App\Models\Identidad\TutorAlumno;
use App\Models\Identidad\Usuario;
use App\Models\Tenant;
use App\Services\MatriculadorOferta;
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
config(['pagos.modo' => 'fake']); // el cobro recorre el flujo sin credenciales
Storage::fake('local');           // el comprobante no toca el disco real

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

/** El archivo va en el bag de FILES: `->file('archivo')` no lo lee de la entrada. */
function comoFamilia(Usuario $usuario, array $datos = [], string $metodo = 'GET', array $archivos = []): Request
{
    $p = Request::create('/', $metodo, $datos, [], $archivos);
    $p->setUserResolver(fn () => $usuario);

    return $p;
}

DB::beginTransaction();

try {
    // ── Escenario ────────────────────────────────────────────────────────────
    PasarelaPago::updateOrCreate(['clave' => 'mercadopago'], ['activa' => true, 'ambiente' => 'pruebas']);

    $concepto = ConceptoPago::where('clave', 'colegiatura')->firstOrFail();
    $matricular = app(MatriculadorOferta::class);

    $hijo = Persona::create(['nombre' => 'Hijo', 'primer_apellido' => 'Pago', 'sexo_id' => 1]);
    $matricula = $matricular->matricular($hijo, Oferta::firstOrFail(), '2026-2030');

    $cargo = function ($m, float $monto) use ($concepto) {
        return Adeudo::create([
            'matricula_oferta_id' => $m->id, 'concepto_id' => $concepto->id,
            'monto' => $monto, 'monto_total' => $monto,
            'fecha_generacion' => '2026-03-01', 'fecha_vencimiento' => '2026-03-10',
        ]);
    };
    $adeudo1 = $cargo($matricula, 1000);
    $adeudo2 = $cargo($matricula, 500);

    // Una matrícula ajena, con su propio cargo (para el filtro por titular).
    $ajenoP = Persona::create(['nombre' => 'Ajeno', 'primer_apellido' => 'Pago', 'sexo_id' => 1]);
    $ajenaM = $matricular->matricular($ajenoP, Oferta::firstOrFail(), '2026-2030');
    $adeudoAjeno = $cargo($ajenaM, 700);

    // El tutor con cuenta, faceta padre, vinculado y con acceso financiero.
    $facetaPadre = Rol::where('name', 'padre_familia')->firstOrFail();
    $facetaPadre->givePermissionTo(['ver-mis-hijos', 'ver-adeudos']);
    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

    $tutor = Persona::create(['nombre' => 'Tutora', 'primer_apellido' => 'Pago', 'sexo_id' => 2]);
    $usuario = Usuario::create([
        'persona_id' => $tutor->id,
        'usuario' => 'tutor_pago_'.random_int(100000, 999999),
        'email' => 'tutor_pago_'.random_int(100000, 999999).'@ejemplo.mx',
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

    auth()->login($usuario);
    (new OperarComoFaceta)->handle(comoFamilia($usuario), fn ($r) => new Response('', 200), 'padre_familia');

    $ctrl = app(PadreApiController::class);
    $ayer = now()->subDay()->toDateString();

    echo PHP_EOL.'1. La lectura del hijo trae el pago y las cuentas'.PHP_EOL;

    $api = json_decode($ctrl->hijo(comoFamilia($usuario), $hijo->fresh())->getContent(), true);
    verificar('Trae el bloque de pago con lo financiero del vínculo', ($api['pago'] ?? null) !== null);
    verificar('Con la pasarela encendida de la escuela',
        collect($api['pago']['pasarelas'] ?? [])->pluck('clave')->contains('mercadopago'));
    verificar('Y el abono mínimo y el pago total', array_key_exists('abono_minimo', $api['pago']) && array_key_exists('pago_total', $api['pago']));
    verificar('Cada matrícula trae sus cuentas para transferencia', array_key_exists('cuentas_bancarias', $api['finanzas'][0] ?? []));

    echo PHP_EOL.'2. Iniciar el cobro: devuelve una URL y nace la intención'.PHP_EOL;

    $resp = json_decode($ctrl->iniciarPago(comoFamilia($usuario, ['pasarela' => 'mercadopago', 'adeudo_ids' => [$adeudo1->id]], 'POST'), $matricula)->getContent(), true);
    verificar('Devuelve la URL de checkout', ! empty($resp['url']));
    verificar('Y queda una intención de cobro de la matrícula',
        IntencionCobro::where('matricula_oferta_id', $matricula->id)->exists());

    echo PHP_EOL.'3. Las guardas de iniciar'.PHP_EOL;

    verificar('Sin cargos elegidos → 422',
        fallo(fn () => $ctrl->iniciarPago(comoFamilia($usuario, ['pasarela' => 'mercadopago'], 'POST'), $matricula)) === 422);
    verificar('Sobre la cuenta de un alumno NO vinculado → 403',
        fallo(fn () => $ctrl->iniciarPago(comoFamilia($usuario, ['pasarela' => 'mercadopago', 'adeudo_ids' => [$adeudoAjeno->id]], 'POST'), $ajenaM)) === 403);
    verificar('Un cargo ajeno sobre la matrícula propia no es facturable → 422',
        fallo(fn () => $ctrl->iniciarPago(comoFamilia($usuario, ['pasarela' => 'mercadopago', 'adeudo_ids' => [$adeudoAjeno->id]], 'POST'), $matricula)) === 422);

    echo PHP_EOL.'4. Subir el comprobante: nace PENDIENTE, con el cargo filtrado'.PHP_EOL;

    $archivo = UploadedFile::fake()->create('deposito.pdf', 100, 'application/pdf');
    $ctrl->subirComprobante(
        comoFamilia($usuario, ['monto' => 1000, 'fecha_transferencia' => $ayer, 'adeudo_ids' => [$adeudo1->id]], 'POST', ['archivo' => $archivo]),
        $matricula,
    );
    $comp = ComprobantePago::where('matricula_oferta_id', $matricula->id)->first();
    verificar('Queda un comprobante PENDIENTE', $comp !== null && $comp->estado === ComprobantePago::PENDIENTE);
    verificar('Con el archivo en el disco (fake)', $comp !== null && Storage::disk('local')->exists($comp->archivo));
    verificar('Y el cargo propio anotado', in_array($adeudo1->id, $comp->adeudo_ids ?? [], true));

    echo PHP_EOL.'5. El filtro de cargos al titular'.PHP_EOL;

    $archivo2 = UploadedFile::fake()->create('deposito2.pdf', 100, 'application/pdf');
    $ctrl->subirComprobante(
        comoFamilia($usuario, ['monto' => 500, 'fecha_transferencia' => $ayer, 'adeudo_ids' => [$adeudoAjeno->id, $adeudo2->id]], 'POST', ['archivo' => $archivo2]),
        $matricula,
    );
    $comp2 = ComprobantePago::where('matricula_oferta_id', $matricula->id)->latest('id')->first();
    verificar('El cargo propio entra', in_array($adeudo2->id, $comp2->adeudo_ids ?? [], true));
    verificar('El cargo AJENO se descarta', ! in_array($adeudoAjeno->id, $comp2->adeudo_ids ?? [], true));

    echo PHP_EOL.'6. Las guardas del comprobante'.PHP_EOL;

    $futuro = UploadedFile::fake()->create('f.pdf', 100, 'application/pdf');
    verificar('Una fecha futura → 422',
        fallo(fn () => $ctrl->subirComprobante(comoFamilia($usuario, ['monto' => 100, 'fecha_transferencia' => now()->addDay()->toDateString()], 'POST', ['archivo' => $futuro]), $matricula)) === 422);
    $ajenoArch = UploadedFile::fake()->create('a.pdf', 100, 'application/pdf');
    verificar('Un comprobante sobre la cuenta ajena → 403',
        fallo(fn () => $ctrl->subirComprobante(comoFamilia($usuario, ['monto' => 100, 'fecha_transferencia' => $ayer], 'POST', ['archivo' => $ajenoArch]), $ajenaM)) === 403);

    echo PHP_EOL.'7. Sin acceso financiero, no viaja el pago'.PHP_EOL;

    TutorAlumno::where('tutor_persona_id', $tutor->id)->where('alumno_persona_id', $hijo->id)->update(['puede_ver_finanzas' => false]);
    $api2 = json_decode($ctrl->hijo(comoFamilia($usuario), $hijo->fresh())->getContent(), true);
    verificar('El bloque de pago es null y no viaja finanzas', $api2['pago'] === null && $api2['finanzas'] === null);
} catch (Throwable $e) {
    verificar('La suite corrió hasta el final sin morir', false,
        get_class($e).': '.$e->getMessage().' @ '.basename($e->getFile()).':'.$e->getLine());
} finally {
    DB::rollBack();
}

echo PHP_EOL.'Resultado: '.($verificaciones - $fallidas).' correctas, '.$fallidas.' fallidas'.PHP_EOL;

exit($fallidas === 0 ? 0 : 1);
