<?php

declare(strict_types=1);

/*
 * El archivo de un reactivo de examen NO puede volverse una lectura arbitraria
 * del disco privado del tenant.
 *
 * El agujero: `responder` acepta un `valor` de cualquier forma y lo guarda tal
 * cual, y la descarga (`ArchivoRespuestaController`) servía `valor.v.ruta` a
 * secas. Un alumno envenenaba SU PROPIA respuesta con una ruta ajena
 * —`examenes/{otro-intento}/...`, un comprobante, un XML de título— y la
 * descarga se la entregaba, porque la respuesta es suya y el candado de
 * pertenencia pasa.
 *
 * Dos redes: la vía genérica se niega a guardar la respuesta de un reactivo de
 * ARCHIVO (ésos suben por `responderArchivo`, que pone la ruta el servidor), y
 * la descarga sólo sirve rutas dentro de `examenes/{intento}/`.
 */

use App\Enums\TipoActividad;
use App\Enums\TipoReactivo;
use App\Http\Controllers\ArchivoRespuestaController;
use App\Models\ControlEscolar\Inscripcion;
use App\Models\Identidad\Usuario;
use App\Models\Lms\Actividad;
use App\Models\Lms\Curso;
use App\Models\Lms\Examen;
use App\Models\Lms\Reactivo;
use App\Models\Lms\Respuesta;
use App\Models\Tenant;
use App\Services\Lms\AplicadorExamen;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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

function req(Usuario $usuario): Request
{
    $p = Request::create('/', 'GET');
    $p->setUserResolver(fn () => $usuario);

    return $p;
}

$intento = null;
$rutaLegit = null;

$db->beginTransaction();

try {
    // ── Escenario: un curso alcanzable por un alumno con cuenta ──────────────
    $usuario = null;
    $inscripcion = null;
    $curso = null;
    foreach (Curso::query()->whereNotNull('asignatura_grupo_id')->get() as $c) {
        foreach (Inscripcion::query()->where('asignatura_grupo_id', $c->asignatura_grupo_id)->get() as $i) {
            $u = Usuario::query()->where('persona_id', optional($i->matriculaOferta)->persona_id)->first();
            if ($u !== null) {
                $usuario = $u;
                $inscripcion = $i;
                $curso = $c;
                break 2;
            }
        }
    }

    if ($usuario === null) {
        throw new RuntimeException('No hay un curso alcanzable por un alumno con cuenta en el demo.');
    }

    auth()->login($usuario);

    $actividad = Actividad::create([
        'curso_id' => $curso->id, 'tipo' => TipoActividad::Examen, 'titulo' => 'Examen con archivo',
        'orden' => 96, 'publicada' => true, 'puntos' => 20,
    ]);
    $examen = Examen::create([
        'actividad_id' => $actividad->id, 'intentos_permitidos' => 1, 'minutos_limite' => null,
        'reactivos_a_presentar' => null, 'barajar_reactivos' => false, 'barajar_opciones' => false,
        'permite_captura' => true, 'una_por_pagina' => false, 'intento_que_cuenta' => Examen::CUENTA_ULTIMO,
        'mostrar_resultado' => Examen::RESULTADO_AL_ENTREGAR,
    ]);
    $archivoR = Reactivo::create(['curso_id' => $curso->id, 'tipo' => TipoReactivo::Archivo, 'enunciado' => 'Sube tu ensayo', 'puntos' => 10]);
    $abiertaR = Reactivo::create(['curso_id' => $curso->id, 'tipo' => TipoReactivo::Abierta, 'enunciado' => 'Explica', 'puntos' => 10]);
    $examen->reactivos()->attach($archivoR->id, ['puntos' => 10, 'orden' => 1]);
    $examen->reactivos()->attach($abiertaR->id, ['puntos' => 10, 'orden' => 2]);

    $aplicador = app(AplicadorExamen::class);
    $intento = $aplicador->iniciar($examen, $inscripcion);

    // ── 1. La vía genérica no fija la respuesta de un reactivo de archivo ─────
    echo PHP_EOL.'1. La vía genérica rechaza un reactivo de archivo'.PHP_EOL;

    $r1 = null;
    try {
        $aplicador->guardarRespuesta($intento->fresh(), $archivoR->id, ['ruta' => 'examenes/99999/robado.pdf', 'nombre' => 'x']);
    } catch (RuntimeException $e) {
        $r1 = $e->getMessage();
    }
    verificar('Guardar un archivo por la vía genérica se rehúsa', $r1 !== null);

    $r2 = null;
    try {
        $aplicador->guardarRespuesta($intento->fresh(), $archivoR->id, 'texto cualquiera');
    } catch (RuntimeException $e) {
        $r2 = $e->getMessage();
    }
    verificar('Se rehúsa aunque el valor sea texto (sigue siendo la vía genérica)', $r2 !== null);

    // La vía de archivo (esArchivo: true) sí guarda, con la ruta del servidor.
    $rutaLegit = "examenes/{$intento->id}/legit.pdf";
    Storage::disk('local')->put($rutaLegit, '%PDF-1.4 archivo legítimo');
    $aplicador->guardarRespuesta($intento->fresh(), $archivoR->id, ['ruta' => $rutaLegit, 'nombre' => 'ensayo.pdf'], esArchivo: true);
    $respArchivo = Respuesta::query()->where('intento_id', $intento->id)->where('reactivo_id', $archivoR->id)->first();
    verificar('La vía de archivo sí guarda la ruta del servidor', ($respArchivo->valor['v']['ruta'] ?? null) === $rutaLegit);

    $ctrl = app(ArchivoRespuestaController::class);

    // ── 2. La descarga sirve el archivo propio ───────────────────────────────
    echo PHP_EOL.'2. La descarga sirve el archivo propio, dentro de la carpeta'.PHP_EOL;

    $respuesta = $ctrl(req($usuario), $respArchivo->fresh());
    verificar('El archivo propio se descarga (200)', $respuesta->getStatusCode() === 200);

    // ── 3. Contención: una ruta FUERA de la carpeta del intento → 404 ─────────
    // Se escribe la Respuesta directo, simulando un envenenamiento que se hubiera
    // colado: es la segunda red, la de la descarga, la que aquí se prueba.
    echo PHP_EOL.'3. Una ruta ajena o con «..» → 404 (no se sirve)'.PHP_EOL;

    Storage::disk('local')->put('examenes/99999/secreto.pdf', 'DATOS PRIVADOS DE OTRO');
    Respuesta::updateOrCreate(
        ['intento_id' => $intento->id, 'reactivo_id' => $abiertaR->id],
        ['valor' => ['v' => ['ruta' => 'examenes/99999/secreto.pdf', 'nombre' => 'loot']]],
    );
    $envenenada = Respuesta::query()->where('intento_id', $intento->id)->where('reactivo_id', $abiertaR->id)->first();
    verificar('Una ruta fuera de la carpeta del intento → 404',
        fallo(fn () => $ctrl(req($usuario), $envenenada->fresh())) === 404);

    // El secreto sigue en disco: la descarga no lo tocó, sólo se negó a servirlo.
    verificar('El archivo ajeno no se entregó (sigue intacto en disco)',
        Storage::disk('local')->get('examenes/99999/secreto.pdf') === 'DATOS PRIVADOS DE OTRO');

    $envenenada->valor = ['v' => ['ruta' => "examenes/{$intento->id}/../99999/secreto.pdf", 'nombre' => 'loot']];
    $envenenada->save();
    verificar('Una ruta con «..» → 404',
        fallo(fn () => $ctrl(req($usuario), $envenenada->fresh())) === 404);
} catch (Throwable $e) {
    verificar('La suite corrió hasta el final sin morir', false,
        get_class($e).': '.$e->getMessage().' @ '.basename($e->getFile()).':'.$e->getLine());
} finally {
    // Los archivos del disco NO los deshace el rollback: se borran a mano.
    if ($intento !== null) {
        Storage::disk('local')->deleteDirectory("examenes/{$intento->id}");
    }
    Storage::disk('local')->deleteDirectory('examenes/99999');
    $db->rollBack();
}

echo PHP_EOL.'Resultado: '.($verificaciones - $fallidas).' correctas, '.$fallidas.' fallidas'.PHP_EOL;
