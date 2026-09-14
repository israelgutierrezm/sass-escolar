<?php

/**
 * API de la app móvil: el docente CIERRA el LMS —califica exámenes a mano y
 * modera foros—. Con rollback.
 *
 * Se corre con `php scripts/prueba-api-docente-lms.php` desde la raíz.
 *
 * ── Qué se vigila ─────────────────────────────────────────────────────────
 *  1. `examenIntentos` lista los intentos entregados con lo que espera revisión.
 *  2. Calificar a mano una respuesta abierta cierra el intento (baja requiere_revision).
 *  3. Una respuesta de un examen de otra materia → 403.
 *  4. El docente ve el foro como MODERADOR y puede fijar/cerrar un tema.
 *  5. El docente retira un tema AJENO (modera); un foro de otra materia → 403.
 *
 * Reusa `AplicadorExamen` (calificarAMano, intentosParaRevisar) y `ForoDeActividad`
 * (los mismos que la web). Escenario construido en la transacción.
 */

use App\Enums\TipoActividad;
use App\Enums\TipoReactivo;
use App\Http\Controllers\Api\DocenteApiController;
use App\Models\ControlEscolar\AsignaturaGrupo;
use App\Models\ControlEscolar\Inscripcion;
use App\Models\Identidad\Usuario;
use App\Models\Lms\Actividad;
use App\Models\Lms\Curso;
use App\Models\Lms\Examen;
use App\Models\Lms\ForoTema;
use App\Models\Lms\Reactivo;
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

function req(Usuario $usuario, array $datos = [], string $metodo = 'POST'): Request
{
    $p = Request::create('/', $metodo, $datos);
    $p->setUserResolver(fn () => $usuario);

    return $p;
}

$db->beginTransaction();

try {
    // ── Escenario: un docente con cuenta que imparte una materia con curso ───
    $docente = null;
    $ag = null;
    $curso = null;
    foreach (Curso::query()->whereNotNull('asignatura_grupo_id')->get() as $c) {
        $g = AsignaturaGrupo::query()->with('docentes')->find($c->asignatura_grupo_id);
        if ($g === null || ! Inscripcion::query()->where('asignatura_grupo_id', $g->id)->exists()) {
            continue;
        }
        foreach ($g->docentes as $d) {
            $u = Usuario::query()->where('persona_id', $d->persona_id)->first();
            if ($u !== null) {
                $docente = $u;
                $ag = $g;
                $curso = $c;
                break 2;
            }
        }
    }

    if ($docente === null) {
        throw new RuntimeException('No hay un docente con cuenta que imparta una materia con curso e inscripciones.');
    }

    auth()->login($docente);
    $inscripcion = Inscripcion::query()->where('asignatura_grupo_id', $ag->id)->first();
    $aplicador = app(AplicadorExamen::class);
    $ctrl = app(DocenteApiController::class);

    // ── Examen con un reactivo ABIERTO (no autocalificable) presentado ───────
    $actExamen = Actividad::create([
        'curso_id' => $curso->id, 'tipo' => TipoActividad::Examen, 'titulo' => 'Examen a mano',
        'orden' => 92, 'publicada' => true, 'puntos' => 10,
    ]);
    $examen = Examen::create([
        'actividad_id' => $actExamen->id, 'intentos_permitidos' => 1, 'minutos_limite' => null,
        'reactivos_a_presentar' => null, 'barajar_reactivos' => false, 'barajar_opciones' => false,
        'permite_captura' => true, 'una_por_pagina' => false, 'intento_que_cuenta' => Examen::CUENTA_ULTIMO,
        'mostrar_resultado' => Examen::RESULTADO_AL_ENTREGAR,
    ]);
    $abierta = Reactivo::create([
        'curso_id' => $curso->id, 'tipo' => TipoReactivo::Abierta, 'enunciado' => 'Explica con tus palabras.', 'puntos' => 10,
    ]);
    $examen->reactivos()->attach($abierta->id, ['puntos' => 10, 'orden' => 1]);

    // El alumno lo presenta y entrega: la abierta queda pendiente de revisión.
    $intento = $aplicador->iniciar($examen, $inscripcion);
    $aplicador->guardarRespuesta($intento, $abierta->id, 'Mi ensayo sobre el tema.');
    $aplicador->entregar($intento->fresh());
    $intento->refresh();

    // ── 1. Los intentos por revisar ──────────────────────────────────────────
    echo PHP_EOL.'1. examenIntentos: los intentos con lo que espera revisión'.PHP_EOL;

    $r = json_decode($ctrl->examenIntentos(req($docente, [], 'GET'), $ag, $actExamen->fresh())->getContent(), true);
    $mio = collect($r['intentos'])->firstWhere('id', $intento->id);
    verificar('El intento entregado aparece', $mio !== null);
    verificar('Marca que requiere revisión', ($mio['requiere_revision'] ?? null) === true);
    verificar('Trae la respuesta abierta como pendiente, con su tope',
        count($mio['pendientes']) === 1 && (float) $mio['pendientes'][0]['tope'] === 10.0);

    // ── 2. Calificar a mano cierra el intento ────────────────────────────────
    echo PHP_EOL.'2. Calificar a mano la abierta cierra el intento'.PHP_EOL;

    $respuesta = $intento->respuestas()->firstWhere('reactivo_id', $abierta->id);
    $res = json_decode($ctrl->calificarRespuesta(req($docente, ['puntos' => 7, 'comentario' => 'Buen punto']), $respuesta->fresh())->getContent(), true);
    verificar('Calificar responde ok', ($res['ok'] ?? null) === true);
    verificar('Ya no requiere revisión', ($res['requiere_revision'] ?? null) === false);
    verificar('La respuesta quedó con sus puntos', (float) $respuesta->fresh()->puntos === 7.0);
    verificar('El intento sumó esos puntos', (float) $intento->fresh()->puntos_obtenidos === 7.0);

    // ── 3. Una respuesta de otra materia → 403 ───────────────────────────────
    echo PHP_EOL.'3. Calificar una respuesta de una materia ajena → 403'.PHP_EOL;

    $agAjena = AsignaturaGrupo::query()
        ->whereKeyNot($ag->id)
        ->whereDoesntHave('docentes', fn ($q) => $q->where('docentes.persona_id', $docente->persona_id))
        ->value('id');

    if ($agAjena !== null) {
        $cursoAjeno = Curso::create(['asignatura_grupo_id' => $agAjena, 'titulo' => 'Curso ajeno (prueba)', 'publicado' => true]);
        $actAjena = Actividad::create(['curso_id' => $cursoAjeno->id, 'tipo' => TipoActividad::Examen, 'titulo' => 'Ajeno', 'orden' => 93, 'publicada' => true, 'puntos' => 10]);
        $examenAjeno = Examen::create([
            'actividad_id' => $actAjena->id, 'intentos_permitidos' => 1, 'barajar_reactivos' => false, 'barajar_opciones' => false,
            'permite_captura' => true, 'una_por_pagina' => false, 'intento_que_cuenta' => Examen::CUENTA_ULTIMO, 'mostrar_resultado' => Examen::RESULTADO_AL_ENTREGAR,
        ]);
        $reactivoAjeno = Reactivo::create(['curso_id' => $cursoAjeno->id, 'tipo' => TipoReactivo::Abierta, 'enunciado' => '¿?', 'puntos' => 10]);
        $examenAjeno->reactivos()->attach($reactivoAjeno->id, ['puntos' => 10, 'orden' => 1]);
        $intentoAjeno = $aplicador->iniciar($examenAjeno, $inscripcion);
        $aplicador->guardarRespuesta($intentoAjeno, $reactivoAjeno->id, 'x');
        $respAjena = $intentoAjeno->respuestas()->firstWhere('reactivo_id', $reactivoAjeno->id);

        verificar('Calificar una respuesta ajena → 403',
            fallo(fn () => $ctrl->calificarRespuesta(req($docente, ['puntos' => 5]), $respAjena->fresh())) === 403);
    } else {
        verificar('OMITIDO: no hay una materia ajena', false, 'escenario incompleto');
    }

    // ── 4. El foro, visto como moderador; fijar/cerrar ───────────────────────
    echo PHP_EOL.'4. El foro como moderador: fijar y cerrar un tema'.PHP_EOL;

    $actForo = Actividad::create([
        'curso_id' => $curso->id, 'tipo' => TipoActividad::Foro, 'titulo' => 'Foro moderado', 'orden' => 94, 'publicada' => true, 'puntos' => 0,
    ]);
    $temaAlumno = ForoTema::create([
        'actividad_id' => $actForo->id, 'persona_id' => optional($inscripcion->matriculaOferta)->persona_id, 'titulo' => 'Tema del alumno', 'cuerpo' => '...',
    ]);

    $ficha = json_decode($ctrl->foro(req($docente, [], 'GET'), $ag, $actForo->fresh())->getContent(), true);
    verificar('El docente ve el foro como moderador', ($ficha['moderador'] ?? null) === true && count($ficha['temas']) === 1);

    $ctrl->moderarForo(req($docente, ['fijado' => true, 'cerrado' => true]), $ag, $actForo->fresh(), $temaAlumno->fresh());
    $temaAlumno->refresh();
    verificar('El tema quedó fijado y cerrado', $temaAlumno->fijado === true && $temaAlumno->cerrado === true);

    // ── 5. Retirar un tema ajeno (modera); foro de otra materia → 403 ────────
    echo PHP_EOL.'5. El docente retira un tema ajeno; un foro ajeno → 403'.PHP_EOL;

    $ctrl->eliminarTemaForo(req($docente, [], 'DELETE'), $ag, $actForo->fresh(), $temaAlumno->fresh());
    verificar('El docente retira el tema del alumno (modera)', ForoTema::query()->whereKey($temaAlumno->id)->doesntExist());

    if ($agAjena !== null) {
        $actForoAjeno = Actividad::create(['curso_id' => Curso::query()->where('asignatura_grupo_id', $agAjena)->value('id'), 'tipo' => TipoActividad::Foro, 'titulo' => 'Foro ajeno', 'orden' => 95, 'publicada' => true, 'puntos' => 0]);
        verificar('Ver el foro de una materia ajena → 403',
            fallo(fn () => $ctrl->foro(req($docente, [], 'GET'), AsignaturaGrupo::find($agAjena), $actForoAjeno->fresh())) === 403);
    } else {
        verificar('OMITIDO: no hay una materia ajena', false, 'escenario incompleto');
    }

    // ── 6. El listado de entregas trae el TIPO de cada actividad ─────────────
    // La app enruta por él: un examen se califica a mano y un foro se modera,
    // cada uno en su pantalla. Sin este campo todo caería como entrega directa.
    echo PHP_EOL.'6. entregas: cada actividad viaja con su tipo'.PHP_EOL;

    $ent = json_decode($ctrl->entregas(req($docente, [], 'GET'), $ag)->getContent(), true);
    $porId = collect($ent['actividades'] ?? [])->keyBy('id');
    verificar('La entrega del examen se marca como examen',
        ($porId[$actExamen->id]['tipo'] ?? null) === $actExamen->tipo->value);
    verificar('La entrega del foro se marca como foro',
        ($porId[$actForo->id]['tipo'] ?? null) === $actForo->tipo->value);
} catch (Throwable $e) {
    verificar('La suite corrió hasta el final sin morir', false,
        get_class($e).': '.$e->getMessage().' @ '.basename($e->getFile()).':'.$e->getLine());
} finally {
    $db->rollBack();
}

echo PHP_EOL.'Resultado: '.($verificaciones - $fallidas).' correctas, '.$fallidas.' fallidas'.PHP_EOL;

exit($fallidas === 0 ? 0 : 1);
