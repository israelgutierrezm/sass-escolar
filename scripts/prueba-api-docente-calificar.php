<?php

/**
 * API de la app móvil: el docente CALIFICA entregas del aula. Con rollback.
 *
 * Se corre con `php scripts/prueba-api-docente-calificar.php` desde la raíz.
 *
 * ── Qué se vigila ─────────────────────────────────────────────────────────
 *  1. `entregas()` lista las actividades entregables con sus entregas.
 *  2. Calificar directo escribe la nota y deja la entrega CALIFICADA.
 *  3. Una nota mayor que los puntos de la actividad se rechaza (422).
 *  4. Una actividad con RÚBRICA no acepta nota directa: exige los criterios.
 *  5. Una entrega de una materia que no imparto → 403.
 *
 * La escritura directa vive en `CalificacionDeEntrega` (la web delega); la de
 * rúbrica en `CalificadorPorRubrica`. Escenario construido en la transacción.
 */

use App\Enums\TipoActividad;
use App\Http\Controllers\Api\DocenteApiController;
use App\Models\ControlEscolar\AsignaturaGrupo;
use App\Models\ControlEscolar\Inscripcion;
use App\Models\Identidad\Usuario;
use App\Models\Lms\Actividad;
use App\Models\Lms\Curso;
use App\Models\Lms\Entrega;
use App\Models\Lms\Rubrica;
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

function req(Usuario $usuario, array $datos = []): Request
{
    $p = Request::create('/', 'POST', $datos);
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

    $crear = fn (array $extra = []) => Actividad::create(array_merge(
        ['curso_id' => $curso->id, 'tipo' => TipoActividad::Actividad, 'titulo' => 'Tarea de prueba', 'orden' => 90, 'publicada' => true, 'puntos' => 10],
        $extra,
    ));

    $actDirecta = $crear();
    $entregaDirecta = Entrega::create([
        'actividad_id' => $actDirecta->id, 'inscripcion_id' => $inscripcion->id,
        'contenido' => 'Mi trabajo', 'estado' => Entrega::ENTREGADA, 'entregada_en' => now(),
    ]);

    $rubricaId = Rubrica::query()->value('id');
    $actRubrica = $rubricaId === null ? null : $crear(['rubrica_id' => $rubricaId]);
    $entregaRubrica = $actRubrica === null ? null : Entrega::create([
        'actividad_id' => $actRubrica->id, 'inscripcion_id' => $inscripcion->id,
        'contenido' => 'Con rúbrica', 'estado' => Entrega::ENTREGADA, 'entregada_en' => now(),
    ]);

    // Una entrega de una materia que este docente NO imparte.
    $agAjena = AsignaturaGrupo::query()
        ->whereKeyNot($ag->id)
        ->whereDoesntHave('docentes', fn ($q) => $q->where('docentes.persona_id', $docente->persona_id))
        ->value('id');
    $entregaAjena = null;
    if ($agAjena !== null) {
        $cursoAjeno = Curso::create(['asignatura_grupo_id' => $agAjena, 'titulo' => 'Curso ajeno (prueba)', 'publicado' => true]);
        $actAjena = Actividad::create(['curso_id' => $cursoAjeno->id, 'tipo' => TipoActividad::Actividad, 'titulo' => 'Ajena', 'orden' => 91, 'publicada' => true, 'puntos' => 10]);
        $entregaAjena = Entrega::create([
            'actividad_id' => $actAjena->id, 'inscripcion_id' => $inscripcion->id,
            'estado' => Entrega::ENTREGADA, 'entregada_en' => now(),
        ]);
    }

    $ctrl = app(DocenteApiController::class);

    // ── 1. Listar las entregas por calificar ─────────────────────────────────
    echo PHP_EOL.'1. entregas(): las actividades entregables con sus entregas'.PHP_EOL;

    $lista = json_decode($ctrl->entregas(req($docente), $ag)->getContent(), true)['actividades'];
    $laDirecta = collect($lista)->firstWhere('id', $actDirecta->id);
    verificar('La actividad aparece con su entrega', $laDirecta !== null && count($laDirecta['entregas']) >= 1);
    verificar('La entrega trae el nombre del alumno', ($laDirecta['entregas'][0]['alumno'] ?? null) !== null);
    verificar('Cuenta al menos una por calificar', ($laDirecta['por_calificar'] ?? 0) >= 1);

    // ── 2. Calificar directo ─────────────────────────────────────────────────
    echo PHP_EOL.'2. Calificar directo escribe la nota'.PHP_EOL;

    $ctrl->calificarEntrega(req($docente, ['calificacion' => 8, 'retroalimentacion' => 'Bien']), $entregaDirecta);
    $entregaDirecta->refresh();
    verificar('La entrega queda con la nota puesta', (float) $entregaDirecta->calificacion === 8.0);
    verificar('...y en estado CALIFICADA', $entregaDirecta->estado === Entrega::CALIFICADA);

    // ── 3. Una nota mayor que los puntos se rechaza ──────────────────────────
    echo PHP_EOL.'3. Una nota fuera de escala se rechaza'.PHP_EOL;

    verificar('Calificar con más de los puntos → validación',
        esValidacion(fn () => $ctrl->calificarEntrega(req($docente, ['calificacion' => 999]), $entregaDirecta->fresh())));

    // ── 4. Una actividad con rúbrica exige los criterios ─────────────────────
    echo PHP_EOL.'4. Con rúbrica, la nota no se teclea: exige criterios'.PHP_EOL;

    if ($entregaRubrica !== null) {
        verificar('Calificar una de rúbrica con nota directa → validación (faltan criterios)',
            esValidacion(fn () => $ctrl->calificarEntrega(req($docente, ['calificacion' => 8]), $entregaRubrica)));
    } else {
        verificar('OMITIDO: no hay rúbrica en el demo', false, 'escenario incompleto');
    }

    // ── 5. Una entrega ajena → 403 ───────────────────────────────────────────
    echo PHP_EOL.'5. Una entrega de una materia que no imparto → 403'.PHP_EOL;

    if ($entregaAjena !== null) {
        verificar('Calificar una entrega ajena → 403',
            fallo(fn () => $ctrl->calificarEntrega(req($docente, ['calificacion' => 5]), $entregaAjena)) === 403);
    } else {
        verificar('OMITIDO: no hay una materia ajena', false, 'escenario incompleto');
    }
} catch (Throwable $e) {
    verificar('La suite corrió hasta el final sin morir', false,
        get_class($e).': '.$e->getMessage().' @ '.basename($e->getFile()).':'.$e->getLine());
} finally {
    $db->rollBack();
}

echo PHP_EOL.'Resultado: '.($verificaciones - $fallidas).' correctas, '.$fallidas.' fallidas'.PHP_EOL;

exit($fallidas === 0 ? 0 : 1);
