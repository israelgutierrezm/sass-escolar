<?php

/**
 * Autorizaciones de familiares: la escuela pide, la familia contesta. Con
 * rollback.
 *
 * Se corre con `php scripts/prueba-autorizaciones.php` desde la raíz.
 *
 * ── Lo que hay que vigilar ─────────────────────────────────────────────────
 *  1. Se emite una fila POR VÍNCULO, no por alumno: quien autoriza es una
 *     persona concreta y su respuesta es suya.
 *  2. Un alumno SIN familiares vinculados se reporta por su nombre. Es el caso
 *     que arruina el trámite: la escuela cree que salió a todos y el día de la
 *     excursión resulta que a tres nunca se les pidió nada.
 *  3. `concedida` en NULL es «no ha contestado» y NO cuenta como negada: la
 *     diferencia es legal, no cosmética.
 *  4. Nadie contesta la de otro, ni una vencida.
 *
 * Los `use` van ARRIBA del arranque a propósito: un alias solo aplica a partir
 * de donde se declara.
 */

use App\Http\Controllers\AutorizacionController;
use App\Models\Identidad\Autorizacion;
use App\Models\Identidad\Parentesco;
use App\Models\Identidad\Persona;
use App\Models\Identidad\TipoAutorizacion;
use App\Models\Identidad\TutorAlumno;
use App\Models\Identidad\Usuario;
use App\Models\Tenant;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

    echo ($ok ? "  \033[32mOK\033[39m   " : "  \033[31mFALLA\033[39m ").$que
        .($detalle !== '' ? "  [{$detalle}]" : '').PHP_EOL;
}

function peticionDe(?Usuario $usuario, string $metodo = 'POST', array $datos = []): Request
{
    $peticion = Request::create('/plataforma/autorizaciones', $metodo, $datos);
    $peticion->setUserResolver(fn () => $usuario);
    $peticion->headers->set('X-Inertia', 'true');

    return $peticion;
}

$db->beginTransaction();

try {
    $control = app(AutorizacionController::class);
    $tipo = TipoAutorizacion::query()->activos()->firstOrFail();

    // Un alumno CON familiares y otro SIN ellos.
    $conFamilia = TutorAlumno::query()->pluck('alumno_persona_id')->unique();

    if ($conFamilia->isEmpty()) {
        echo 'Esta escuela no tiene vínculos familiares; nada que probar.'.PHP_EOL;
        $db->rollBack();
        exit(0);
    }

    /*
     * Uno con DOS familiares, y no el primero que aparezca.
     *
     * Con un solo vínculo, «una fila por vínculo» y «una por alumno» dan el
     * mismo número: la verificación pasaría sin distinguir las dos cosas, que
     * es justo lo que viene a distinguir. Si la escuela no tiene ninguno con
     * dos, se le agrega el segundo aquí —dentro de la transacción—.
     */
    $conDos = TutorAlumno::query()
        ->selectRaw('alumno_persona_id, count(*) as n')
        ->groupBy('alumno_persona_id')
        ->havingRaw('count(*) >= 2')
        ->value('alumno_persona_id');

    if ($conDos === null) {
        $conDos = (int) $conFamilia->first();
        $otroTutor = Persona::query()
            ->whereNotIn('id', TutorAlumno::query()->where('alumno_persona_id', $conDos)->select('tutor_persona_id'))
            ->whereKeyNot($conDos)
            ->firstOrFail();

        TutorAlumno::create([
            'tutor_persona_id' => $otroTutor->id,
            'alumno_persona_id' => $conDos,
            'parentesco_id' => Parentesco::query()->value('id'),
        ]);
    }

    $alumnoConFamilia = (int) $conDos;
    $huerfano = Persona::query()->whereNotIn('id', $conFamilia)->firstOrFail();
    $cuantosVinculos = TutorAlumno::query()->where('alumno_persona_id', $alumnoConFamilia)->count();

    $emisor = Usuario::query()->where('usuario', 'demo')->firstOrFail();

    echo PHP_EOL.'1. Se emite una fila por VÍNCULO, no por alumno'.PHP_EOL;

    $respuesta = $control->emitir(peticionDe($emisor, 'POST', [
        'tipo_autorizacion_id' => $tipo->id,
        'titulo' => 'Visita al museo (prueba)',
        'detalle' => 'Salida de 9 a 14 h.',
        'fecha_limite' => now()->addWeek()->toDateString(),
        'alumnos' => [$alumnoConFamilia, $huerfano->id],
    ]));

    $emitidas = Autorizacion::query()->where('titulo', 'Visita al museo (prueba)')->get();

    verificar('el alumno de prueba tiene MÁS DE UN familiar —si no, esto no distingue—',
        $cuantosVinculos >= 2, (string) $cuantosVinculos);
    verificar('una por cada familiar vinculado, no una por alumno',
        $emitidas->count() === $cuantosVinculos, $emitidas->count().' de '.$cuantosVinculos);
    verificar('todas nacen sin contestar',
        $emitidas->every(fn (Autorizacion $a) => $a->concedida === null));

    echo PHP_EOL.'2. El alumno sin familiares se reporta por su nombre'.PHP_EOL;

    $aviso = (string) ($respuesta->getSession()->get('advertencia') ?? '');

    verificar('el acuse lo advierte', str_contains($aviso, 'no tienen familiares vinculados'), $aviso);
    verificar('y lo nombra', str_contains($aviso, $huerfano->nombreCompleto()), $huerfano->nombreCompleto());

    echo PHP_EOL.'3. El familiar contesta la SUYA'.PHP_EOL;

    $suya = $emitidas->first();
    $vinculo = TutorAlumno::query()->whereKey($suya->vinculo_familiar_id)->firstOrFail();
    $familiar = Usuario::query()->where('persona_id', $vinculo->tutor_persona_id)->firstOrFail();

    $control->responder(
        peticionDe($familiar, 'PUT', ['concedida' => true, 'comentario' => 'De acuerdo']),
        $suya,
    );

    $suya->refresh();

    verificar('queda concedida', $suya->concedida === true);
    verificar('con fecha de respuesta', $suya->fecha_respuesta !== null);
    verificar('y con su comentario', $suya->comentario === 'De acuerdo');

    echo PHP_EOL.'4. Sin contestar NO es lo mismo que negada'.PHP_EOL;

    $pendientes = Autorizacion::query()->where('titulo', 'Visita al museo (prueba)')->pendientes()->count();
    $negadas = Autorizacion::query()->where('titulo', 'Visita al museo (prueba)')->where('concedida', false)->count();

    verificar('las que faltan siguen pendientes', $pendientes === $cuantosVinculos - 1, (string) $pendientes);
    verificar('y ninguna cuenta como negada', $negadas === 0, (string) $negadas);

    echo PHP_EOL.'5. Nadie contesta la de otro'.PHP_EOL;

    $otro = Usuario::query()
        ->whereNotNull('persona_id')
        ->whereNotIn('persona_id', TutorAlumno::query()->select('tutor_persona_id'))
        ->firstOrFail();

    $bloqueado = false;

    try {
        $control->responder(peticionDe($otro, 'PUT', ['concedida' => false]), $suya);
    } catch (HttpException $e) {
        $bloqueado = $e->getStatusCode() === 404;
    }

    verificar('responde 404: esa autorización no existe para él', $bloqueado);

    echo PHP_EOL.'6. Una vencida ya no se contesta ni se cambia'.PHP_EOL;

    // Se vence a mano: emitirla vencida lo impide la validación, que es otra
    // de las cosas que hay que comprobar.
    $suya->update(['fecha_limite' => now()->subDay()->toDateString()]);

    $bloqueado = false;

    try {
        $control->responder(peticionDe($familiar, 'PUT', ['concedida' => false]), $suya->refresh());
    } catch (HttpException $e) {
        $bloqueado = $e->getStatusCode() === 404;
    }

    verificar('ni el propio familiar puede cambiarla', $bloqueado);
    verificar('y su respuesta anterior sigue en pie', $suya->refresh()->concedida === true);

    echo PHP_EOL.'7. No se emite con el plazo ya vencido'.PHP_EOL;

    $rechazada = false;

    try {
        $control->emitir(peticionDe($emisor, 'POST', [
            'tipo_autorizacion_id' => $tipo->id,
            'titulo' => 'Nace vencida',
            'fecha_limite' => now()->subDay()->toDateString(),
            'alumnos' => [$alumnoConFamilia],
        ]));
    } catch (ValidationException) {
        $rechazada = true;
    }

    verificar('la validación lo impide', $rechazada);
} finally {
    $db->rollBack();
    echo PHP_EOL.'-- rollback aplicado, la base queda como estaba --'.PHP_EOL;
}

echo PHP_EOL.'Resultado: '.($verificaciones - $fallidas)." correctas, {$fallidas} fallidas".PHP_EOL;

exit($fallidas === 0 ? 0 : 1);
