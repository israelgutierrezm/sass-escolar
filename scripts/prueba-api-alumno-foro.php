<?php

/**
 * API de la app móvil: el alumno participa en un FORO. Con rollback.
 *
 * Se corre con `php scripts/prueba-api-alumno-foro.php` desde la raíz.
 *
 * ── Qué se vigila ─────────────────────────────────────────────────────────
 *  1. El foro lista sus temas y dice quién soy (para saber qué es mío).
 *  2. Abrir un tema lo registra Y deja constancia de la participación (entrega).
 *  3. Responder cuelga la respuesta; responder a una respuesta NO anida un 2º nivel.
 *  4. Retiro un tema MÍO; el de otra persona → 403 (el alumno no modera).
 *  5. Un foro cerrado no deja abrir tema (422); un tema cerrado no deja responder (422).
 *
 * La regla vive en `ForoDeActividad` (el mismo servicio que la web). Escenario
 * construido en la transacción.
 */

use App\Enums\TipoActividad;
use App\Http\Controllers\Api\AlumnoApiController;
use App\Models\ControlEscolar\Inscripcion;
use App\Models\Identidad\Persona;
use App\Models\Identidad\Usuario;
use App\Models\Lms\Actividad;
use App\Models\Lms\Curso;
use App\Models\Lms\Entrega;
use App\Models\Lms\ForoRespuesta;
use App\Models\Lms\ForoTema;
use App\Models\Tenant;
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
    $miPersona = (int) $usuario->persona_id;
    $otraPersona = (int) Persona::query()->where('id', '!=', $miPersona)->value('id');

    $actividad = Actividad::create([
        'curso_id' => $curso->id, 'tipo' => TipoActividad::Foro, 'titulo' => 'Foro de prueba',
        'orden' => 94, 'publicada' => true, 'puntos' => 10,
    ]);

    $ctrl = app(AlumnoApiController::class);

    // ── 1. La ficha del foro ─────────────────────────────────────────────────
    echo PHP_EOL.'1. El foro lista sus temas y dice quién soy'.PHP_EOL;

    $ficha = json_decode($ctrl->foro(req($usuario), $actividad->fresh())->getContent(), true);
    verificar('Arranca sin temas', $ficha['temas'] === []);
    verificar('«yo» es mi persona', ($ficha['yo'] ?? null) === $miPersona);

    // ── 2. Abrir un tema deja constancia ─────────────────────────────────────
    echo PHP_EOL.'2. Abrir un tema lo registra y cuenta como participación'.PHP_EOL;

    $r = json_decode($ctrl->crearTemaForo(req($usuario, ['titulo' => 'Mi duda', 'cuerpo' => '¿Cómo integro por partes?']), $actividad->fresh())->getContent(), true);
    $temaId = $r['tema_id'] ?? null;
    verificar('Crear tema devuelve su id', is_int($temaId));

    $ficha = json_decode($ctrl->foro(req($usuario), $actividad->fresh())->getContent(), true);
    verificar('El foro ya lo lista', count($ficha['temas']) === 1 && $ficha['temas'][0]['titulo'] === 'Mi duda');

    $entrega = Entrega::query()->where('actividad_id', $actividad->id)->where('inscripcion_id', $inscripcion->id)->first();
    verificar('Participar dejó una entrega registrada', $entrega !== null && $entrega->entregada_en !== null);

    // ── 3. Responder, y el anidado de un solo nivel ──────────────────────────
    echo PHP_EOL.'3. Responder cuelga la respuesta; no hay segundo nivel'.PHP_EOL;

    $tema = ForoTema::findOrFail($temaId);
    $resp = json_decode($ctrl->responderForo(req($usuario, ['cuerpo' => 'Prueba con u=x']), $actividad->fresh(), $tema->fresh())->getContent(), true);
    verificar('Responder al tema guarda', ($resp['ok'] ?? null) === true);

    $primera = ForoRespuesta::query()->where('foro_tema_id', $tema->id)->orderBy('id')->first();
    verificar('La primera respuesta es de primer nivel', $primera !== null && $primera->responde_a_id === null);

    // Responder A esa respuesta: debe colgar de ella (primer nivel), no anidar más.
    $ctrl->responderForo(req($usuario, ['cuerpo' => 'Gracias', 'responde_a_id' => $primera->id]), $actividad->fresh(), $tema->fresh());
    $anidada = ForoRespuesta::query()->where('foro_tema_id', $tema->id)->orderByDesc('id')->first();
    verificar('Responder a una respuesta cuelga de la de primer nivel', (int) $anidada->responde_a_id === (int) $primera->id);

    // Y responder a ESA respuesta anidada NO abre un tercer nivel: se aplana al
    // primero. Es lo que distingue el `?? $padre->responde_a_id`.
    $ctrl->responderForo(req($usuario, ['cuerpo' => 'De nada', 'responde_a_id' => $anidada->id]), $actividad->fresh(), $tema->fresh());
    $tercera = ForoRespuesta::query()->where('foro_tema_id', $tema->id)->orderByDesc('id')->first();
    verificar('Responder a una respuesta anidada se aplana al primer nivel', (int) $tercera->responde_a_id === (int) $primera->id);

    // El tema abierto se pide con `?tema=` (query de un GET).
    $peticionTema = Request::create('/', 'GET', ['tema' => $tema->id]);
    $peticionTema->setUserResolver(fn () => $usuario);
    $abierto = json_decode($ctrl->foro($peticionTema, $actividad->fresh())->getContent(), true);
    verificar('El tema abierto trae su respuesta con la anidada colgando',
        ($abierto['abierto']['respuestas'][0]['hijas'][0]['cuerpo'] ?? null) === 'Gracias');

    // ── 4. Retirar lo mío sí; lo ajeno no ────────────────────────────────────
    echo PHP_EOL.'4. Retiro mi tema; el ajeno me responde 403'.PHP_EOL;

    $ajeno = ForoTema::create([
        'actividad_id' => $actividad->id, 'persona_id' => $otraPersona, 'titulo' => 'Tema de otro', 'cuerpo' => '...',
    ]);
    verificar('Un tema ajeno no lo puedo retirar → 403',
        fallo(fn () => $ctrl->eliminarTemaForo(req($usuario), $actividad->fresh(), $ajeno->fresh())) === 403);

    $ctrl->eliminarTemaForo(req($usuario), $actividad->fresh(), $tema->fresh());
    verificar('Mi tema sí se retira', ForoTema::query()->whereKey($tema->id)->doesntExist());

    // ── 5. Foro y tema cerrados ──────────────────────────────────────────────
    echo PHP_EOL.'5. Un foro/tema cerrado no deja participar'.PHP_EOL;

    // Tema cerrado dentro de un foro abierto.
    $temaAbierto = ForoTema::create([
        'actividad_id' => $actividad->id, 'persona_id' => $miPersona, 'titulo' => 'Cerrado', 'cuerpo' => '...', 'cerrado' => true,
    ]);
    verificar('Un tema cerrado no deja responder → 422',
        fallo(fn () => $ctrl->responderForo(req($usuario, ['cuerpo' => 'hola']), $actividad->fresh(), $temaAbierto->fresh())) === 422);

    // Foro cerrado: le ponemos fecha de cierre en el pasado.
    $actividad->update(['cierra_en' => now()->subDay()]);
    verificar('Un foro cerrado no deja abrir tema → 422',
        fallo(fn () => $ctrl->crearTemaForo(req($usuario, ['titulo' => 'Tarde', 'cuerpo' => 'x']), $actividad->fresh())) === 422);
} catch (Throwable $e) {
    verificar('La suite corrió hasta el final sin morir', false,
        get_class($e).': '.$e->getMessage().' @ '.basename($e->getFile()).':'.$e->getLine());
} finally {
    $db->rollBack();
}

echo PHP_EOL.'Resultado: '.($verificaciones - $fallidas).' correctas, '.$fallidas.' fallidas'.PHP_EOL;

exit($fallidas === 0 ? 0 : 1);
