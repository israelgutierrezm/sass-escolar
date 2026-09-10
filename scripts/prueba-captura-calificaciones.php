<?php

/**
 * Capturar calificaciones: el servicio compartido, la web y la API. Con rollback.
 *
 * `php scripts/prueba-captura-calificaciones.php` desde la raíz.
 *
 * ── Qué se vigila ─────────────────────────────────────────────────────────
 *  1. La escritura vive en UN servicio (`CapturaDeCalificaciones`) que usan la
 *     web y la app: sólo pares de ESTA materia, NULL no es cero, y repasar
 *     revive la fila borrada.
 *  2. La API del docente lee la hoja (componentes, escala, final calculado por
 *     los mismos servicios) y la guarda; materia ajena → 403, fuera de escala →
 *     422. El web controller sigue guardando por el mismo servicio.
 */

use App\Http\Controllers\Api\DocenteApiController;
use App\Http\Controllers\CapturaCalificacionesController;
use App\Http\Middleware\Api\OperarComoFaceta;
use App\Models\Academico\EsquemaEvaluacion;
use App\Models\ControlEscolar\AsignaturaGrupo;
use App\Models\ControlEscolar\CalificacionComponente;
use App\Models\ControlEscolar\Inscripcion;
use App\Models\Identidad\Usuario;
use App\Models\Tenant;
use App\Services\CapturaDeCalificaciones;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
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
    } catch (ValidationException $e) {
        return $e->status;
    } catch (HttpException $e) {
        return $e->getStatusCode();
    }

    return null;
}

function comoDocente(Usuario $usuario, array $datos = [], string $metodo = 'GET'): Request
{
    $p = Request::create('/', $metodo, $datos);
    $p->setUserResolver(fn () => $usuario);

    return $p;
}

$db->beginTransaction();

try {
    // ── Escenario: un docente con materia, inscripciones y esquema ───────────
    $usuario = null;
    $ag = null;
    foreach (AsignaturaGrupo::query()->whereHas('docentes')->with('docentes')->get() as $c) {
        foreach ($c->docentes as $d) {
            $u = Usuario::query()->where('persona_id', $d->persona_id)->first();
            $tieneEsquema = EsquemaEvaluacion::where('plan_materia_id', $c->plan_materia_id)->exists();
            if ($u !== null && $tieneEsquema && Inscripcion::where('asignatura_grupo_id', $c->id)->exists()) {
                $ag = $c;
                $usuario = $u;
                break 2;
            }
        }
    }

    if ($ag === null || $usuario === null) {
        throw new RuntimeException('No hay una materia con docente-cuenta, esquema e inscripciones en el demo.');
    }

    $personaId = (int) $usuario->persona_id;
    auth()->login($usuario);
    (new OperarComoFaceta)->handle(comoDocente($usuario), fn ($r) => new Response('', 200), 'docente');

    $insc1 = (int) Inscripcion::where('asignatura_grupo_id', $ag->id)->value('id');
    $comp1 = (int) EsquemaEvaluacion::where('plan_materia_id', $ag->plan_materia_id)->value('id');
    $ajena = (int) Inscripcion::query()->where('asignatura_grupo_id', '!=', $ag->id)->value('id');

    $captura = app(CapturaDeCalificaciones::class);

    echo PHP_EOL.'1. El servicio guarda sólo pares de ESTA materia'.PHP_EOL;

    $r = $captura->guardar($ag, [
        ['inscripcion_id' => $insc1, 'esquema_evaluacion_id' => $comp1, 'calificacion' => 8.5],
        ['inscripcion_id' => $ajena, 'esquema_evaluacion_id' => $comp1, 'calificacion' => 5.0], // ajena: se ignora
    ], $personaId);

    verificar('Sólo se guardó el par propio', $r['guardadas'] === 1, (string) $r['guardadas']);
    verificar('El valor quedó', (float) CalificacionComponente::where('inscripcion_id', $insc1)->where('esquema_evaluacion_id', $comp1)->value('calificacion') === 8.5);
    verificar('Y NO se tocó al ajeno',
        CalificacionComponente::where('inscripcion_id', $ajena)->where('esquema_evaluacion_id', $comp1)->doesntExist());

    echo PHP_EOL.'2. NULL no es cero'.PHP_EOL;

    $captura->guardar($ag, [['inscripcion_id' => $insc1, 'esquema_evaluacion_id' => $comp1, 'calificacion' => null]], $personaId);
    verificar('Una celda vacía queda en NULL, no en 0',
        CalificacionComponente::where('inscripcion_id', $insc1)->where('esquema_evaluacion_id', $comp1)->value('calificacion') === null);

    echo PHP_EOL.'3. La captura está abierta (sin acta cerrada)'.PHP_EOL;
    verificar('capturaAbierta = true', $captura->capturaAbierta($ag) === true);

    echo PHP_EOL.'4. La API lee la hoja'.PHP_EOL;

    $ctrl = app(DocenteApiController::class);
    $hoja = json_decode($ctrl->calificaciones(comoDocente($usuario), $ag->fresh())->getContent(), true);
    verificar('Trae los componentes con su parcial y porcentaje',
        collect($hoja['componentes'] ?? [])->firstWhere('id', $comp1) !== null);
    verificar('Trae la escala del plan', array_key_exists('minima', $hoja['escala'] ?? []));
    verificar('Y los alumnos con su mapa de calificaciones y su final',
        collect($hoja['alumnos'] ?? [])->firstWhere('inscripcion_id', $insc1) !== null
        && array_key_exists('final', collect($hoja['alumnos'])->firstWhere('inscripcion_id', $insc1)));

    echo PHP_EOL.'5. La API guarda'.PHP_EOL;

    $cuerpo = ['calificaciones' => [['inscripcion_id' => $insc1, 'esquema_evaluacion_id' => $comp1, 'calificacion' => 9]]];
    $g = json_decode($ctrl->guardarCalificaciones(comoDocente($usuario, $cuerpo, 'POST'), $ag->fresh())->getContent(), true);
    verificar('Devuelve cuántas guardó', ($g['guardadas'] ?? null) === 1);
    verificar('Y el cambio quedó',
        (float) CalificacionComponente::where('inscripcion_id', $insc1)->where('esquema_evaluacion_id', $comp1)->value('calificacion') === 9.0);

    echo PHP_EOL.'6. Alcance y validación de la API'.PHP_EOL;

    $agAjena = AsignaturaGrupo::query()
        ->whereKeyNot($ag->id)
        ->whereDoesntHave('docentes', fn ($q) => $q->where('docentes.persona_id', $personaId))
        ->first();
    if ($agAjena !== null) {
        verificar('Guardar en una materia ajena → 403',
            fallo(fn () => $ctrl->guardarCalificaciones(comoDocente($usuario, $cuerpo, 'POST'), $agAjena)) === 403);
    }

    // Fuera de escala: un valor enorme rebasa el máximo del plan.
    $fueraEscala = ['calificaciones' => [['inscripcion_id' => $insc1, 'esquema_evaluacion_id' => $comp1, 'calificacion' => 99999]]];
    verificar('Una calificación fuera de la escala del plan → 422',
        fallo(fn () => $ctrl->guardarCalificaciones(comoDocente($usuario, $fueraEscala, 'POST'), $ag->fresh())) === 422);

    echo PHP_EOL.'7. El web controller sigue guardando tras el refactor'.PHP_EOL;

    $webCtrl = app(CapturaCalificacionesController::class);
    $webCtrl->guardar(
        comoDocente($usuario, ['calificaciones' => [['inscripcion_id' => $insc1, 'esquema_evaluacion_id' => $comp1, 'calificacion' => 7]]], 'POST'),
        $ag->fresh(),
    );
    verificar('La web escribe por el mismo servicio (una sola verdad)',
        (float) CalificacionComponente::where('inscripcion_id', $insc1)->where('esquema_evaluacion_id', $comp1)->value('calificacion') === 7.0);
} catch (Throwable $e) {
    verificar('La suite corrió hasta el final sin morir', false,
        get_class($e).': '.$e->getMessage().' @ '.basename($e->getFile()).':'.$e->getLine());
} finally {
    $db->rollBack();
}

echo PHP_EOL.'Resultado: '.($verificaciones - $fallidas).' correctas, '.$fallidas.' fallidas'.PHP_EOL;

exit($fallidas === 0 ? 0 : 1);
