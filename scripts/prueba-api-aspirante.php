<?php

/**
 * API de la app móvil: el ASPIRANTE y su solicitud (núcleo). Con rollback.
 *
 * Se corre con `php scripts/prueba-api-aspirante.php` desde la raíz.
 *
 * ── Qué se vigila ─────────────────────────────────────────────────────────
 *  1. El panorama trae avance, datos, documentos, cargos y los catálogos.
 *  2. Guardar datos escribe en la persona; datos inválidos → 422.
 *  3. Subir un documento lo registra PENDIENTE de revisión.
 *  4. Sin solicitud abierta → 404.
 *
 * Todo sale de `AutoservicioSolicitud` (el mismo servicio y las mismas reglas
 * que la web). El archivo va a un disco fingido; nada toca el disco real.
 */

use App\Http\Controllers\Api\AspiranteApiController;
use App\Models\Admisiones\Aspirante;
use App\Models\Admisiones\DocumentoRequerido;
use App\Models\Admisiones\ExpedienteDocumento;
use App\Models\Identidad\Usuario;
use App\Models\Landlord\Genero;
use App\Models\Tenant;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

tenancy()->initialize(Tenant::find('demo'));

$db = DB::connection('tenant');

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
    } catch (HttpException $e) {
        return $e->getStatusCode();
    }

    return null;
}

function esValidacion(callable $accion): bool
{
    try {
        $accion();
    } catch (ValidationException) {
        return true;
    }

    return false;
}

function req(Usuario $usuario, array $datos = [], string $metodo = 'POST'): Request
{
    $p = Request::create('/', $metodo, $datos);
    $p->setUserResolver(fn () => $usuario);

    return $p;
}

$db->beginTransaction();
Storage::fake('local');

try {
    $aspirante = Aspirante::query()->whereNotNull('persona_id')
        ->whereHas('persona', fn ($q) => $q->whereHas('usuario'))->orderByDesc('id')->first();
    $usuario = $aspirante === null ? null : Usuario::query()->where('persona_id', $aspirante->persona_id)->first();

    if ($usuario === null) {
        throw new RuntimeException('No hay un aspirante con cuenta en el demo.');
    }

    auth()->login($usuario);
    $ctrl = app(AspiranteApiController::class);

    // ── 1. El panorama ───────────────────────────────────────────────────────
    echo PHP_EOL.'1. El panorama de la solicitud'.PHP_EOL;

    $p = json_decode($ctrl->solicitud(req($usuario, [], 'GET'))->getContent(), true);
    verificar('Trae el avance de los pasos', isset($p['progreso']));
    verificar('Trae los datos de la persona', array_key_exists('persona', $p) && array_key_exists('curp', $p['persona']));
    verificar('Trae los documentos del ámbito aspirante', is_array($p['documentos']));
    verificar('Trae los cargos con su saldo', array_key_exists('cargos', $p) && array_key_exists('saldo', $p['cargos']));
    verificar('Trae los catálogos para editar (géneros y ofertas)', count($p['generos']) > 0 && count($p['ofertas']) > 0);

    // ── 2. Guardar datos ──────────────────────────────────────────────────────
    echo PHP_EOL.'2. Guardar datos escribe en la persona'.PHP_EOL;

    $genero = (int) Genero::query()->value('id');
    $oferta = (int) ($aspirante->oferta_interes_id ?? \App\Models\Academico\Oferta::query()->value('id'));
    $base = [
        'nombre' => 'Nombre Prueba', 'primer_apellido' => 'Apellido', 'segundo_apellido' => 'Segundo',
        'curp' => 'EXTRANJERO', 'email' => $aspirante->persona->email ?? 'aspirante.prueba@demo.mx',
        'celular' => '5512345678', 'genero_id' => $genero, 'oferta_id' => $oferta,
    ];

    $ctrl->guardarDatos(req($usuario, $base, 'PUT'));
    verificar('El nombre quedó guardado en la persona', $aspirante->persona->fresh()->nombre === 'Nombre Prueba');

    verificar('Guardar sin nombre → validación',
        esValidacion(fn () => $ctrl->guardarDatos(req($usuario, [...$base, 'nombre' => ''], 'PUT'))));

    // ── 3. Subir un documento ──────────────────────────────────────────────────
    echo PHP_EOL.'3. Subir un documento lo deja PENDIENTE'.PHP_EOL;

    $documentoId = (int) DB::table('documento_ambitos')
        ->where('ambito', DocumentoRequerido::AMBITO_ASPIRANTE)->value('documento_id');

    if ($documentoId > 0) {
        $peticionDoc = Request::create('/', 'POST', ['documento_id' => $documentoId], [], [
            'archivo' => UploadedFile::fake()->create('acta.pdf', 20, 'application/pdf'),
        ]);
        $peticionDoc->setUserResolver(fn () => $usuario);
        $ctrl->subirDocumento($peticionDoc);

        $entrega = ExpedienteDocumento::query()
            ->where('aspirante_id', $aspirante->id)->where('documento_id', $documentoId)->first();
        verificar('Se registró el documento', $entrega !== null && $entrega->url !== null);
        verificar('Quedó en estado PENDIENTE', $entrega?->estado?->clave === 'pendiente');
    } else {
        verificar('OMITIDO: el demo no pide documentos de aspirante', false, 'escenario incompleto');
    }

    // ── 4. Sin solicitud → 404 ─────────────────────────────────────────────────
    echo PHP_EOL.'4. Sin solicitud abierta → 404'.PHP_EOL;

    $sinSolicitud = Usuario::query()->whereNotNull('persona_id')
        ->whereNotIn('persona_id', Aspirante::query()->pluck('persona_id'))->first();

    if ($sinSolicitud !== null) {
        verificar('Un usuario sin solicitud → 404',
            fallo(fn () => $ctrl->solicitud(req($sinSolicitud, [], 'GET'))) === 404);
    } else {
        verificar('OMITIDO: no hay un usuario sin solicitud', false, 'escenario incompleto');
    }
} catch (Throwable $e) {
    verificar('La suite corrió hasta el final sin morir', false,
        get_class($e).': '.$e->getMessage().' @ '.basename($e->getFile()).':'.$e->getLine());
} finally {
    $db->rollBack();
}

echo PHP_EOL.'Resultado: '.($verificaciones - $fallidas).' correctas, '.$fallidas.' fallidas'.PHP_EOL;

exit($fallidas === 0 ? 0 : 1);
