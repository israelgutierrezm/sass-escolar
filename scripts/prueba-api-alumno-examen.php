<?php

/**
 * API de la app móvil: el alumno PRESENTA un examen. Con rollback.
 *
 * Se corre con `php scripts/prueba-api-alumno-examen.php` desde la raíz.
 *
 * ── Qué se vigila ─────────────────────────────────────────────────────────
 *  1. La ficha del examen deja iniciar cuando no hay intento en curso.
 *  2. Iniciar abre un intento; el intento trae sus reactivos y lo contestado.
 *  3. Responder guarda; entregar califica lo automático y muestra el resultado.
 *  4. Un intento que no es mío → 403.
 *
 * El motor es `AplicadorExamen` (el mismo que la web); la regla de cuándo se ve
 * el resultado vive en `resultadoVisible`. Escenario construido en la transacción.
 */

use App\Enums\TipoActividad;
use App\Enums\TipoReactivo;
use App\Http\Controllers\Api\AlumnoApiController;
use App\Models\ControlEscolar\Inscripcion;
use App\Models\Identidad\Usuario;
use App\Models\Lms\Actividad;
use App\Models\Lms\Curso;
use App\Models\Lms\Examen;
use App\Models\Lms\Intento;
use App\Models\Lms\Reactivo;
use App\Models\Lms\ReactivoOpcion;
use App\Models\Tenant;
use App\Services\Lms\AplicadorExamen;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

function req(Usuario $usuario, array $datos = []): Request
{
    $p = Request::create('/', 'POST', $datos);
    $p->setUserResolver(fn () => $usuario);

    return $p;
}

$db->beginTransaction();

try {
    // ── Escenario: un curso alcanzable por un alumno con cuenta ──────────────
    $usuario = null;
    $inscripcion = null;
    $curso = null;
    $otroInsc = null;
    foreach (Curso::query()->whereNotNull('asignatura_grupo_id')->get() as $c) {
        $insc = Inscripcion::query()->where('asignatura_grupo_id', $c->asignatura_grupo_id)->get();
        foreach ($insc as $i) {
            $u = Usuario::query()->where('persona_id', optional($i->matriculaOferta)->persona_id)->first();
            if ($u !== null) {
                $usuario = $u;
                $inscripcion = $i;
                $curso = $c;
                $otroInsc = $insc->first(fn (Inscripcion $x) => (int) $x->id !== (int) $i->id);
                break 2;
            }
        }
    }

    if ($usuario === null) {
        throw new RuntimeException('No hay un curso alcanzable por un alumno con cuenta en el demo.');
    }

    auth()->login($usuario);

    $actividad = Actividad::create([
        'curso_id' => $curso->id, 'tipo' => TipoActividad::Examen, 'titulo' => 'Examen de prueba',
        'orden' => 95, 'publicada' => true, 'puntos' => 10,
    ]);
    $examen = Examen::create([
        'actividad_id' => $actividad->id, 'intentos_permitidos' => 1, 'minutos_limite' => null,
        'reactivos_a_presentar' => null, 'barajar_reactivos' => false, 'barajar_opciones' => false,
        'permite_captura' => true, 'una_por_pagina' => false, 'intento_que_cuenta' => Examen::CUENTA_ULTIMO,
        'mostrar_resultado' => Examen::RESULTADO_AL_ENTREGAR,
    ]);
    $reactivo = Reactivo::create([
        'curso_id' => $curso->id, 'tipo' => TipoReactivo::OpcionUnica, 'enunciado' => '¿2 + 2?', 'puntos' => 10,
    ]);
    $buena = ReactivoOpcion::create(['reactivo_id' => $reactivo->id, 'texto' => '4', 'correcta' => true, 'orden' => 1]);
    ReactivoOpcion::create(['reactivo_id' => $reactivo->id, 'texto' => '5', 'correcta' => false, 'orden' => 2]);
    $examen->reactivos()->attach($reactivo->id, ['puntos' => 10, 'orden' => 1]);

    $ctrl = app(AlumnoApiController::class);

    // ── 1. La ficha del examen ───────────────────────────────────────────────
    echo PHP_EOL.'1. La ficha deja iniciar'.PHP_EOL;

    $ficha = json_decode($ctrl->examen(req($usuario), $actividad->fresh())->getContent(), true);
    verificar('puede_iniciar en verdadero, sin intentos aún', ($ficha['puede_iniciar'] ?? null) === true && $ficha['intentos'] === []);

    // ── 2. Iniciar y ver el intento ──────────────────────────────────────────
    echo PHP_EOL.'2. Iniciar abre el intento con sus reactivos'.PHP_EOL;

    $r = json_decode($ctrl->iniciarExamen(req($usuario), $actividad->fresh())->getContent(), true);
    $intentoId = $r['intento_id'] ?? null;
    verificar('Iniciar devuelve el id del intento', is_int($intentoId));

    $intento = Intento::findOrFail($intentoId);
    $vista = json_decode($ctrl->intento(req($usuario), $intento)->getContent(), true);
    verificar('El intento está abierto y trae el reactivo', ($vista['entregado'] ?? null) === false && count($vista['reactivos']) === 1);
    verificar('El reactivo trae sus opciones', count($vista['reactivos'][0]['opciones'] ?? []) === 2);

    // ── 3. Responder y entregar ──────────────────────────────────────────────
    echo PHP_EOL.'3. Responder y entregar califica lo automático'.PHP_EOL;

    $resp = json_decode($ctrl->responderExamen(req($usuario, ['reactivo_id' => $reactivo->id, 'valor' => $buena->id]), $intento)->getContent(), true);
    verificar('Responder guarda', ($resp['guardado'] ?? null) === true);

    $fin = json_decode($ctrl->entregarExamen(req($usuario), $intento->fresh())->getContent(), true);
    verificar('Entregar cierra el intento', ($fin['entregado'] ?? null) === true);
    verificar('La opción correcta da el puntaje completo', (float) ($fin['resultado']['puntos_obtenidos'] ?? 0) === 10.0);

    // ── 4. Un intento ajeno → 403 ────────────────────────────────────────────
    echo PHP_EOL.'4. Un intento que no es mío → 403'.PHP_EOL;

    if ($otroInsc !== null) {
        $ajeno = app(AplicadorExamen::class)->iniciar($examen, $otroInsc);
        verificar('Ver un intento ajeno → 403',
            fallo(fn () => $ctrl->intento(req($usuario), $ajeno)) === 403);
    } else {
        verificar('OMITIDO: no hay una segunda inscripción', false, 'escenario incompleto');
    }
} catch (Throwable $e) {
    verificar('La suite corrió hasta el final sin morir', false,
        get_class($e).': '.$e->getMessage().' @ '.basename($e->getFile()).':'.$e->getLine());
} finally {
    $db->rollBack();
}

echo PHP_EOL.'Resultado: '.($verificaciones - $fallidas).' correctas, '.$fallidas.' fallidas'.PHP_EOL;

exit($fallidas === 0 ? 0 : 1);
