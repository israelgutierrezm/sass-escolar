<?php

/**
 * API de la app móvil: el AULA del alumno —entregar y marcar lecturas—. Rollback.
 *
 * Se corre con `php scripts/prueba-api-alumno-entrega.php` desde la raíz.
 *
 * ── Qué se vigila ─────────────────────────────────────────────────────────
 *  1. Entregar exige contenido o archivo; una lectura no se entrega.
 *  2. Entregar guarda la entrega (y sus archivos), marca «tarde» si ya cerró.
 *  3. Una sola entrega cuando el docente no permite reentrega.
 *  4. El candado del prerrequisito bloquea la entrega (403) hasta completarlo.
 *  5. Completar/descompletar una LECTURA (una tarea se completa entregándola).
 *  6. Una actividad de una materia que no curso → 403.
 *
 * La regla vive en `EntregaDeActividad`, compartido con la web. Storage::fake y
 * rollback; las actividades auxiliares se crean dentro de la transacción.
 */

use App\Enums\TipoActividad;
use App\Http\Controllers\Api\AlumnoApiController;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\ControlEscolar\AsignaturaGrupo;
use App\Models\ControlEscolar\Inscripcion;
use App\Models\Identidad\Usuario;
use App\Models\Lms\Actividad;
use App\Models\Lms\ActividadVista;
use App\Models\Lms\Curso;
use App\Models\Lms\Entrega;
use App\Models\Lms\EntregaArchivo;
use App\Models\Tenant;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
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

/** Una petición con el usuario resuelto, con cuerpo y/o archivos. */
function req(Usuario $usuario, array $datos = [], array $files = []): Request
{
    $p = Request::create('/', 'POST', $datos, [], $files);
    $p->setUserResolver(fn () => $usuario);

    return $p;
}

function pdfFalso(): UploadedFile
{
    return UploadedFile::fake()->create('tarea.pdf', 30, 'application/pdf');
}

$db->beginTransaction();

try {
    Storage::fake('local');

    // ── Escenario: una actividad entregable alcanzable por un alumno con cuenta
    $actividadEntrega = null;
    $inscripcion = null;
    $usuario = null;
    foreach (Actividad::query()->with('curso')->get() as $act) {
        if (! $act->tipo->seEntrega()) {
            continue;
        }
        $agId = $act->curso?->asignatura_grupo_id;
        if ($agId === null) {
            continue;
        }
        foreach (Inscripcion::query()->where('asignatura_grupo_id', $agId)->get() as $i) {
            $u = Usuario::query()->where('persona_id', optional($i->matriculaOferta)->persona_id)->first();
            if ($u !== null) {
                $actividadEntrega = $act;
                $inscripcion = $i;
                $usuario = $u;
                break 2;
            }
        }
    }

    if ($usuario === null) {
        throw new RuntimeException('No hay una actividad entregable alcanzable por un alumno con cuenta en el demo.');
    }

    auth()->login($usuario);
    $cursoId = $actividadEntrega->curso_id;
    $misMatriculas = MatriculaOferta::query()->where('persona_id', $usuario->persona_id)->pluck('id');

    $crear = fn (TipoActividad $tipo, array $extra = []) => Actividad::create(array_merge(
        ['curso_id' => $cursoId, 'tipo' => $tipo, 'titulo' => 'Prueba '.$tipo->value, 'orden' => 90, 'publicada' => true],
        $extra,
    ));

    $lectura = $crear(TipoActividad::Lectura);
    $reentregaOff = $crear(TipoActividad::Actividad, ['permite_reentrega' => false]);
    $conPrereq = $crear(TipoActividad::Actividad, ['prerequisito_id' => $lectura->id]);
    $tarde = $crear(TipoActividad::Actividad, ['cierra_en' => now()->subDay(), 'permite_tarde' => true]);

    // Una actividad de una materia que este alumno NO cursa: se construye en un
    // curso de un grupo donde no está inscrito.
    $agsDelAlumno = Inscripcion::query()->whereIn('matricula_oferta_id', $misMatriculas)->pluck('asignatura_grupo_id')->all();
    $agAjena = AsignaturaGrupo::query()->whereNotIn('id', $agsDelAlumno)->value('id');
    $ajena = null;
    if ($agAjena !== null) {
        $cursoAjeno = Curso::create(['asignatura_grupo_id' => $agAjena, 'titulo' => 'Curso ajeno (prueba)', 'publicado' => true]);
        $ajena = $crear(TipoActividad::Actividad);
        $ajena->forceFill(['curso_id' => $cursoAjeno->id])->save();
    }

    $ctrl = app(AlumnoApiController::class);
    Entrega::query()->where('inscripcion_id', $inscripcion->id)->forceDelete();

    // ── 1. Entregar: contenido/archivo obligatorio, y no una lectura ─────────
    echo PHP_EOL.'1. Entregar: exige algo, y una lectura no se entrega'.PHP_EOL;

    verificar('Entregar sin contenido ni archivo → 422',
        fallo(fn () => $ctrl->entregarActividad(req($usuario), $actividadEntrega)) === 422);
    verificar('Entregar una LECTURA → 422',
        fallo(fn () => $ctrl->entregarActividad(req($usuario, ['contenido' => 'x']), $lectura)) === 422);

    // ── 2. Entregar guarda la entrega y sus archivos ─────────────────────────
    echo PHP_EOL.'2. Entregar guarda la entrega (y sus archivos)'.PHP_EOL;

    $r = json_decode($ctrl->entregarActividad(req($usuario, ['contenido' => 'Mi ensayo']), $actividadEntrega)->getContent(), true);
    verificar('Entregar con contenido responde ok', ($r['ok'] ?? null) === true);
    $entrega = Entrega::query()->where('actividad_id', $actividadEntrega->id)->where('inscripcion_id', $inscripcion->id)->first();
    verificar('Queda una entrega ENTREGADA con el contenido', $entrega?->estado === Entrega::ENTREGADA && $entrega->contenido === 'Mi ensayo');

    $ctrl->entregarActividad(req($usuario, ['contenido' => 'Con archivo'], ['archivos' => [pdfFalso()]]), $actividadEntrega);
    verificar('Reentregar con archivo lo guarda',
        EntregaArchivo::query()->where('entrega_id', $entrega->id)->exists());

    // ── 3. Una sola entrega si no permite reentrega ──────────────────────────
    echo PHP_EOL.'3. Sin reentrega, la segunda entrega se rehúsa'.PHP_EOL;

    $ctrl->entregarActividad(req($usuario, ['contenido' => 'Primera']), $reentregaOff);
    verificar('La segunda entrega de una actividad sin reentrega → 422',
        fallo(fn () => $ctrl->entregarActividad(req($usuario, ['contenido' => 'Segunda']), $reentregaOff)) === 422);

    // ── 4. El candado del prerrequisito ──────────────────────────────────────
    echo PHP_EOL.'4. El prerrequisito bloquea la entrega hasta completarlo'.PHP_EOL;

    verificar('Entregar con el prerrequisito sin completar → 403',
        fallo(fn () => $ctrl->entregarActividad(req($usuario, ['contenido' => 'x']), $conPrereq)) === 403);
    $ctrl->completarActividad(req($usuario), $lectura);
    $r = fallo(fn () => $ctrl->entregarActividad(req($usuario, ['contenido' => 'ya puedo']), $conPrereq));
    verificar('Completado el prerrequisito, ya se puede entregar', $r === null);

    // ── 5. Completar / descompletar una lectura ──────────────────────────────
    echo PHP_EOL.'5. Completar y descompletar una lectura'.PHP_EOL;

    verificar('Marcar una TAREA como lectura → 422',
        fallo(fn () => $ctrl->completarActividad(req($usuario), $actividadEntrega)) === 422);

    // la lectura ya se completó arriba; se comprueba y luego se descompleta.
    $vista = ActividadVista::query()->where('actividad_id', $lectura->id)->where('inscripcion_id', $inscripcion->id)->first();
    verificar('La lectura quedó completada', $vista?->completada_en !== null);
    $ctrl->descompletarActividad(req($usuario), $lectura);
    verificar('Descompletar la deja sin completar', $vista->fresh()->completada_en === null);

    // ── 6. Marcar «tarde», y una actividad ajena → 403 ───────────────────────
    echo PHP_EOL.'6. «Tarde» cuando ya cerró; una actividad ajena → 403'.PHP_EOL;

    $r = json_decode($ctrl->entregarActividad(req($usuario, ['contenido' => 'fuera de tiempo']), $tarde)->getContent(), true);
    verificar('Entregar una que ya cerró (con tarde permitido) marca «tarde»', ($r['tarde'] ?? null) === true);

    if ($ajena !== null) {
        verificar('Entregar una actividad de una materia que no curso → 403',
            fallo(fn () => $ctrl->entregarActividad(req($usuario, ['contenido' => 'x']), $ajena)) === 403);
    } else {
        verificar('OMITIDO: no hay una actividad ajena en el demo', false, 'escenario incompleto');
    }
} catch (Throwable $e) {
    verificar('La suite corrió hasta el final sin morir', false,
        get_class($e).': '.$e->getMessage().' @ '.basename($e->getFile()).':'.$e->getLine());
} finally {
    $db->rollBack();
}

echo PHP_EOL.'Resultado: '.($verificaciones - $fallidas).' correctas, '.$fallidas.' fallidas'.PHP_EOL;

exit($fallidas === 0 ? 0 : 1);
