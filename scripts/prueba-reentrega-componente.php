<?php

declare(strict_types=1);

/*
 * Una reentrega —o un reintento de examen que queda pendiente— NO puede dejar
 * en el parcial la nota del trabajo que se reemplazó.
 *
 * El componente del parcial (`CalificacionComponente`) está MATERIALIZADO: es
 * una fila que se escribe al calificar. Reentregar limpia la calificación de la
 * entrega, pero nadie recomputaba el componente, así que el acta seguía
 * mostrando la nota vieja hasta que el docente volviera a calificar. Aquí se
 * comprueba que el componente se recompone —y se retira si ya no queda nada
 * calificado— en los dos caminos, y que calificar a mano bloquea el intento.
 */

use App\Enums\TipoActividad;
use App\Enums\TipoReactivo;
use App\Models\Academico\EsquemaEvaluacion;
use App\Models\ControlEscolar\AsignaturaGrupo;
use App\Models\ControlEscolar\CalificacionComponente;
use App\Models\ControlEscolar\Inscripcion;
use App\Models\Identidad\Usuario;
use App\Models\Lms\Actividad;
use App\Models\Lms\Curso;
use App\Models\Lms\Entrega;
use App\Models\Lms\Examen;
use App\Models\Lms\Reactivo;
use App\Models\Lms\Respuesta;
use App\Models\Tenant;
use App\Services\Lms\AplicadorExamen;
use App\Services\Lms\CalificacionDeEntrega;
use App\Services\Lms\EntregaDeActividad;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

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

$tieneComponente = fn (int $insc, int $comp): bool => CalificacionComponente::query()
    ->where('inscripcion_id', $insc)->where('esquema_evaluacion_id', $comp)->exists();

$db->beginTransaction();

try {
    // ── Escenario: una materia con curso, inscripción de un alumno con cuenta ─
    $usuario = null;
    $inscripcion = null;
    $curso = null;
    $planMateria = null;
    foreach (AsignaturaGrupo::query()->get() as $ag) {
        $c = Curso::query()->where('asignatura_grupo_id', $ag->id)->first();
        if ($c === null) {
            continue;
        }
        foreach (Inscripcion::query()->where('asignatura_grupo_id', $ag->id)->get() as $i) {
            $u = Usuario::query()->where('persona_id', optional($i->matriculaOferta)->persona_id)->first();
            if ($u !== null) {
                $usuario = $u;
                $inscripcion = $i;
                $curso = $c;
                $planMateria = $ag->plan_materia_id;
                break 2;
            }
        }
    }

    if ($usuario === null) {
        throw new RuntimeException('No hay un curso con inscripción de un alumno con cuenta en el demo.');
    }

    auth()->login($usuario);
    $insc = (int) $inscripcion->id;

    // Componentes FRESCOS, para que sólo mis actividades los alimenten y el
    // escenario no dependa de calificaciones ya capturadas del demo.
    $compTarea = EsquemaEvaluacion::create([
        'plan_materia_id' => $planMateria, 'componente' => 'lms_prueba_tarea', 'parcial' => 1, 'porcentaje' => 10, 'orden' => 91,
    ])->id;
    $compExamen = EsquemaEvaluacion::create([
        'plan_materia_id' => $planMateria, 'componente' => 'lms_prueba_examen', 'parcial' => 1, 'porcentaje' => 10, 'orden' => 92,
    ])->id;

    $entregas = app(EntregaDeActividad::class);
    $aplic = app(AplicadorExamen::class);

    // ── 1. Reentrega de una tarea (3d) ───────────────────────────────────────
    echo PHP_EOL.'1. Reentregar una tarea calificada retira su nota del parcial'.PHP_EOL;

    $tarea = Actividad::create([
        'curso_id' => $curso->id, 'tipo' => TipoActividad::Actividad, 'titulo' => 'Tarea ponderada',
        'orden' => 97, 'publicada' => true, 'puntos' => 10, 'esquema_evaluacion_id' => $compTarea, 'permite_reentrega' => true,
    ]);

    $r1 = $entregas->entregar($tarea->fresh(), $inscripcion, 'Primera versión', []);
    verificar('La primera entrega se registró', $r1['error'] === null && $r1['entrega'] !== null);

    app(CalificacionDeEntrega::class)->directa($r1['entrega']->fresh(), 9.0, 'Bien', (int) $usuario->id);
    verificar('Calificar escribió el componente del parcial', $tieneComponente($insc, $compTarea));

    $r2 = $entregas->entregar($tarea->fresh(), $inscripcion, 'Segunda versión', []);
    verificar('La reentrega se registró', $r2['error'] === null);
    verificar('La reentrega RETIRÓ la nota del parcial (no cuenta el trabajo reemplazado)',
        ! $tieneComponente($insc, $compTarea));
    verificar('Y la entrega quedó sin calificar',
        $r2['entrega']->estado === Entrega::ENTREGADA && $r2['entrega']->calificacion === null);

    // ── 2. Reintento de examen que queda pendiente (3e) ──────────────────────
    echo PHP_EOL.'2. Un reintento de examen pendiente retira la nota del parcial'.PHP_EOL;

    $actEx = Actividad::create([
        'curso_id' => $curso->id, 'tipo' => TipoActividad::Examen, 'titulo' => 'Examen ponderado',
        'orden' => 98, 'publicada' => true, 'puntos' => 10, 'esquema_evaluacion_id' => $compExamen,
    ]);
    $examen = Examen::create([
        'actividad_id' => $actEx->id, 'intentos_permitidos' => 2, 'minutos_limite' => null,
        'reactivos_a_presentar' => null, 'barajar_reactivos' => false, 'barajar_opciones' => false,
        'permite_captura' => true, 'una_por_pagina' => false, 'intento_que_cuenta' => Examen::CUENTA_ULTIMO,
        'mostrar_resultado' => Examen::RESULTADO_AL_ENTREGAR,
    ]);
    $abierta = Reactivo::create(['curso_id' => $curso->id, 'tipo' => TipoReactivo::Abierta, 'enunciado' => 'Explica', 'puntos' => 10]);
    $examen->reactivos()->attach($abierta->id, ['puntos' => 10, 'orden' => 1]);

    // Intento 1: se responde, se entrega (queda pendiente por la abierta) y el
    // docente la califica → deja de estar pendiente y el parcial recibe la nota.
    $i1 = $aplic->iniciar($examen, $inscripcion);
    $aplic->guardarRespuesta($i1->fresh(), $abierta->id, 'Mi ensayo del primer intento');
    $aplic->entregar($i1->fresh());
    $respAbierta = Respuesta::query()->where('intento_id', $i1->id)->where('reactivo_id', $abierta->id)->first();

    // Se captura el bloqueo del intento al calificar a mano (1b).
    $conLock = [];
    DB::listen(function ($q) use (&$conLock) {
        if (str_contains(strtolower($q->sql), 'for update')) {
            $conLock[] = strtolower($q->sql);
        }
    });
    $aplic->calificarAMano($respAbierta->fresh(), 8.0);

    verificar('Tras calificar el examen, el parcial tiene su componente', $tieneComponente($insc, $compExamen));
    verificar('Calificar a mano bloquea el intento (SELECT … FOR UPDATE sobre intentos)',
        collect($conLock)->contains(fn (string $sql) => str_contains($sql, 'intentos')));

    // Intento 2: se abre y se entrega SIN calificarse → queda pendiente, y como
    // es el que cuenta (CUENTA_ULTIMO), el parcial deja de tener nota.
    $i2 = $aplic->iniciar($examen->fresh(), $inscripcion);
    verificar('Se abrió un segundo intento', (int) $i2->id !== (int) $i1->id);
    // Se responde la abierta: contestada pero sin calificar, el intento queda
    // PENDIENTE (una abierta sin responder valdría cero, que no es pendiente).
    $aplic->guardarRespuesta($i2->fresh(), $abierta->id, 'Ensayo del segundo intento');
    $aplic->entregar($i2->fresh());

    verificar('El reintento pendiente RETIRÓ la nota del parcial',
        ! $tieneComponente($insc, $compExamen));
} catch (Throwable $e) {
    verificar('La suite corrió hasta el final sin morir', false,
        get_class($e).': '.$e->getMessage().' @ '.basename($e->getFile()).':'.$e->getLine());
} finally {
    $db->rollBack();
}

echo PHP_EOL.'Resultado: '.($verificaciones - $fallidas).' correctas, '.$fallidas.' fallidas'.PHP_EOL;
