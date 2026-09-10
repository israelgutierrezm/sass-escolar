<?php

/**
 * API de la app móvil: las autorizaciones de la familia (leer, responder,
 * revocar). Con rollback. Construye su escenario porque el demo no tiene ninguna.
 *
 * `php scripts/prueba-api-autorizaciones-familia.php` desde la raíz.
 *
 * ── Qué se vigila ─────────────────────────────────────────────────────────
 *  1. La lectura y la escritura salen de UN servicio (`RespuestaAutorizacion`)
 *     que usan la web y la API.
 *  2. Responder concede/niega mientras el plazo siga abierto; una vencida o de
 *     otro vínculo → 404.
 *  3. Revocar retira lo EN VIGOR y lo deja distinto de una negada.
 *  4. El web controller sigue respondiendo por el mismo servicio.
 */

use App\Http\Controllers\Api\PadreApiController;
use App\Http\Controllers\AutorizacionController;
use App\Http\Middleware\Api\OperarComoFaceta;
use App\Models\Identidad\Autorizacion;
use App\Models\Identidad\Persona;
use App\Models\Identidad\TipoAutorizacion;
use App\Models\Identidad\TutorAlumno;
use App\Models\Identidad\Usuario;
use App\Models\Tenant;
use App\Services\Familia\RespuestaAutorizacion;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
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

function comoFamilia(Usuario $usuario, array $datos = [], string $metodo = 'GET'): Request
{
    $p = Request::create('/', $metodo, $datos);
    $p->setUserResolver(fn () => $usuario);

    return $p;
}

$db->beginTransaction();

try {
    // ── Escenario: un tutor con cuenta y un vínculo ──────────────────────────
    $vinculo = TutorAlumno::query()->whereHas('tutor.usuario')->first();
    if ($vinculo === null) {
        throw new RuntimeException('No hay un tutor con cuenta en el demo.');
    }

    $usuario = Usuario::query()->where('persona_id', $vinculo->tutor_persona_id)->firstOrFail();
    // Un vínculo de OTRO tutor (para la autorización ajena).
    $ajeno = TutorAlumno::query()->where('tutor_persona_id', '!=', $vinculo->tutor_persona_id)->first();

    $tipoId = TipoAutorizacion::query()->value('id');

    $mkAut = fn (int $vinculoId, ?string $limite, $concedida = null) => Autorizacion::create([
        'vinculo_familiar_id' => $vinculoId,
        'tipo_autorizacion_id' => $tipoId,
        'titulo' => 'Salida al museo',
        'detalle' => 'Permiso para la visita del 20 de este mes.',
        'fecha_limite' => $limite,
        'vigencia_hasta' => null,
        'concedida' => $concedida,
    ]);

    $pendiente = $mkAut($vinculo->id, now()->addDays(5)->format('Y-m-d')); // plazo abierto
    $vencida = $mkAut($vinculo->id, now()->subDays(2)->format('Y-m-d')); // plazo pasado

    $servicio = app(RespuestaAutorizacion::class);
    auth()->login($usuario);
    (new OperarComoFaceta)->handle(comoFamilia($usuario), fn ($r) => new Response('', 200), 'padre_familia');

    echo PHP_EOL.'1. El servicio lista las autorizaciones de la familia'.PHP_EOL;

    $lista = $servicio->lista($usuario);
    $enLista = collect($lista)->firstWhere('id', $pendiente->id);
    verificar('La pendiente aparece', $enLista !== null);
    verificar('Con estado pendiente y se puede responder',
        ($enLista['estado'] ?? null) === 'pendiente' && ($enLista['puede_responder'] ?? false) === true);

    echo PHP_EOL.'2. La API lee la lista'.PHP_EOL;

    $ctrl = app(PadreApiController::class);
    $apiLista = json_decode($ctrl->autorizaciones(comoFamilia($usuario))->getContent(), true);
    verificar('La API trae las autorizaciones', collect($apiLista['autorizaciones'] ?? [])->firstWhere('id', $pendiente->id) !== null);

    echo PHP_EOL.'3. Responder: conceder'.PHP_EOL;

    $ctrl->responder(comoFamilia($usuario, ['concedida' => true], 'PUT'), $pendiente->fresh());
    $pendiente->refresh();
    verificar('Quedó concedida', $pendiente->concedida === true);
    verificar('Su estado es en_vigor y se puede revocar', $pendiente->estado() === 'en_vigor' && $pendiente->puedeRevocar());

    echo PHP_EOL.'4. Revocar: retira lo en vigor'.PHP_EOL;

    $ctrl->revocar(comoFamilia($usuario, ['comentario' => 'Ya no irá'], 'POST'), $pendiente->fresh());
    $pendiente->refresh();
    verificar('Quedó revocada, distinta de negada', $pendiente->estado() === 'revocada' && $pendiente->concedida === true && $pendiente->revocada_en !== null);

    echo PHP_EOL.'5. Los guardas: vencida y ajena'.PHP_EOL;

    verificar('Responder una vencida → 404',
        fallo(fn () => $ctrl->responder(comoFamilia($usuario, ['concedida' => true], 'PUT'), $vencida->fresh())) === 404);

    if ($ajeno !== null) {
        $deOtro = $mkAut($ajeno->id, now()->addDays(5)->format('Y-m-d'));
        verificar('Responder la de OTRO vínculo → 404',
            fallo(fn () => $ctrl->responder(comoFamilia($usuario, ['concedida' => true], 'PUT'), $deOtro->fresh())) === 404);
    }

    echo PHP_EOL.'6. El web controller responde por el mismo servicio'.PHP_EOL;

    $otra = $mkAut($vinculo->id, now()->addDays(5)->format('Y-m-d'));
    $webCtrl = app(AutorizacionController::class);
    $webCtrl->responder(comoFamilia($usuario, ['concedida' => false], 'PUT'), $otra->fresh());
    verificar('La web negó por el mismo servicio (una sola verdad)', $otra->fresh()->concedida === false);
} catch (Throwable $e) {
    verificar('La suite corrió hasta el final sin morir', false,
        get_class($e).': '.$e->getMessage().' @ '.basename($e->getFile()).':'.$e->getLine());
} finally {
    $db->rollBack();
}

echo PHP_EOL.'Resultado: '.($verificaciones - $fallidas).' correctas, '.$fallidas.' fallidas'.PHP_EOL;

exit($fallidas === 0 ? 0 : 1);
