<?php

/**
 * Pasar lista: el servicio compartido, la web y la API de la app. Con rollback.
 *
 * `php scripts/prueba-pase-de-lista.php` desde la raíz.
 *
 * ── Qué se vigila ─────────────────────────────────────────────────────────
 *  1. La escritura vive en UN servicio (`PaseDeLista`) que usan la web y la
 *     app: sólo alumnos de ESTA materia, y repasar el mismo día CORRIGE sin
 *     duplicar —reviviendo la fila borrada (la trampa del 1062)—.
 *  2. `hoja()` devuelve lo ya marcado y el acumulado de faltas.
 *  3. La API del docente lee la hoja y la guarda; una materia ajena → 403 y una
 *     fecha futura → 422. El web controller sigue guardando tras el refactor.
 */

use App\Http\Controllers\Api\DocenteApiController;
use App\Http\Controllers\PaseListaController;
use App\Http\Middleware\Api\OperarComoFaceta;
use App\Models\Asistencia\AsistenciaClase;
use App\Models\ControlEscolar\AsignaturaGrupo;
use App\Models\ControlEscolar\Inscripcion;
use App\Models\Identidad\Usuario;
use App\Models\Tenant;
use App\Services\Asistencia\PaseDeLista;
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
        return $e->status; // 422 por omisión
    } catch (HttpException $e) {
        return $e->getStatusCode();
    }

    return null;
}

function comoDocente(Usuario $usuario, array $datos = [], string $metodo = 'GET'): Request
{
    // OJO: nada de `app()->instance('request', $p)` —el rebinding de Laravel pisa
    // el resolver con el del guard y `user()` sale null (trampa documentada)—.
    $p = Request::create('/', $metodo, $datos);
    $p->setUserResolver(fn () => $usuario);

    return $p;
}

$db->beginTransaction();

try {
    // ── Escenario: un docente con su materia y sus inscripciones ─────────────
    $usuario = null;
    $ag = null;
    foreach (AsignaturaGrupo::query()->whereHas('docentes')->with('docentes')->get() as $candidata) {
        foreach ($candidata->docentes as $d) {
            $u = Usuario::query()->where('persona_id', $d->persona_id)->first();
            if ($u !== null && Inscripcion::where('asignatura_grupo_id', $candidata->id)->exists()) {
                $ag = $candidata;
                $usuario = $u;
                break 2;
            }
        }
    }

    if ($ag === null || $usuario === null) {
        throw new RuntimeException('No hay una materia con docente-cuenta e inscripciones en el demo.');
    }

    $personaId = (int) $usuario->persona_id;
    auth()->login($usuario); // para los caminos que resuelven por el guard
    (new OperarComoFaceta)->handle(comoDocente($usuario), fn ($r) => new Response('', 200), 'docente');

    $inscripciones = Inscripcion::where('asignatura_grupo_id', $ag->id)->pluck('id')->values();
    $insc1 = (int) $inscripciones[0];
    $hoy = now()->format('Y-m-d');

    $pase = app(PaseDeLista::class);

    // Una inscripción de OTRA materia, para probar que se ignora.
    $ajena = (int) Inscripcion::query()->where('asignatura_grupo_id', '!=', $ag->id)->value('id');

    echo PHP_EOL.'1. El servicio guarda sólo alumnos de ESTA materia'.PHP_EOL;

    $guardadas = $pase->guardar($ag, $hoy, 'unica', [
        ['inscripcion_id' => $insc1, 'estatus' => 'presente'],
        ['inscripcion_id' => $ajena, 'estatus' => 'falta'], // ajena: se ignora
    ], $personaId);

    verificar('Sólo se guardó el alumno propio (la ajena se ignora)', $guardadas === 1, (string) $guardadas);
    verificar('Y NO se creó renglón para la inscripción ajena',
        AsistenciaClase::where('inscripcion_id', $ajena)->whereDate('fecha', $hoy)->where('modalidad', 'unica')->doesntExist());

    echo PHP_EOL.'2. La hoja refleja lo marcado'.PHP_EOL;

    $hoja = $pase->hoja($ag, $hoy, 'unica');
    $fila1 = collect($hoja)->firstWhere('inscripcion_id', $insc1);
    verificar('El alumno aparece con su estatus de hoy', ($fila1['estatus'] ?? null) === 'presente');

    echo PHP_EOL.'3. Repasar CORRIGE, no duplica —y revive la fila borrada—'.PHP_EOL;

    // Se borra y se vuelve a pasar: el `updateOrCreate` normal chocaría con el
    // único de la base (1062); `actualizarOReviver` la revive.
    AsistenciaClase::where('inscripcion_id', $insc1)->whereDate('fecha', $hoy)->where('modalidad', 'unica')->delete();
    $pase->guardar($ag, $hoy, 'unica', [['inscripcion_id' => $insc1, 'estatus' => 'retardo']], $personaId);

    $filas = AsistenciaClase::withTrashed()->where('inscripcion_id', $insc1)->whereDate('fecha', $hoy)->where('modalidad', 'unica')->get();
    verificar('Sigue habiendo UNA sola fila (revivida, no duplicada)', $filas->count() === 1, (string) $filas->count());
    verificar('Con el estatus corregido y viva', $filas->first()->estatus === 'retardo' && $filas->first()->deleted_at === null);

    echo PHP_EOL.'4. La API del docente: leer la hoja'.PHP_EOL;

    $ctrl = app(DocenteApiController::class);
    $resp = json_decode($ctrl->asistencia(comoDocente($usuario, ['fecha' => $hoy]), $ag->fresh())->getContent(), true);
    verificar('Trae la fecha, las modalidades y los estatus válidos',
        ($resp['fecha'] ?? null) === $hoy && is_array($resp['modalidades'] ?? null) && in_array('presente', $resp['estatus'] ?? [], true));
    verificar('Y el roster con lo ya marcado',
        collect($resp['alumnos'] ?? [])->firstWhere('inscripcion_id', $insc1)['estatus'] === 'retardo');

    echo PHP_EOL.'5. La API del docente: guardar la lista'.PHP_EOL;

    $cuerpo = ['fecha' => $hoy, 'modalidad' => 'unica', 'asistencias' => [['inscripcion_id' => $insc1, 'estatus' => 'presente']]];
    $g = json_decode($ctrl->guardarAsistencia(comoDocente($usuario, $cuerpo, 'POST'), $ag->fresh())->getContent(), true);
    verificar('Devuelve cuántas guardó', ($g['guardadas'] ?? null) === 1);
    verificar('Y el cambio quedó',
        AsistenciaClase::where('inscripcion_id', $insc1)->whereDate('fecha', $hoy)->where('modalidad', 'unica')->value('estatus') === 'presente');

    echo PHP_EOL.'6. Alcance y validación de la API'.PHP_EOL;

    if ($ajena > 0) {
        $agAjena = AsignaturaGrupo::query()
            ->whereKeyNot($ag->id)
            ->whereDoesntHave('docentes', fn ($q) => $q->where('docentes.persona_id', $personaId))
            ->first();
        if ($agAjena !== null) {
            verificar('Guardar en una materia ajena → 403',
                fallo(fn () => $ctrl->guardarAsistencia(comoDocente($usuario, $cuerpo, 'POST'), $agAjena)) === 403);
        }
    }

    $futuro = now()->addDays(3)->format('Y-m-d');
    verificar('Una fecha futura → 422',
        fallo(fn () => $ctrl->guardarAsistencia(
            comoDocente($usuario, ['fecha' => $futuro, 'modalidad' => 'unica', 'asistencias' => [['inscripcion_id' => $insc1, 'estatus' => 'presente']]], 'POST'),
            $ag->fresh(),
        )) === 422);

    echo PHP_EOL.'7. El web controller sigue guardando tras el refactor'.PHP_EOL;

    $webCtrl = app(PaseListaController::class);
    $webCtrl->guardar(
        comoDocente($usuario, ['fecha' => $hoy, 'modalidad' => 'unica', 'asistencias' => [['inscripcion_id' => $insc1, 'estatus' => 'justificada']]], 'POST'),
        $ag->fresh(),
    );
    verificar('La web escribe por el mismo servicio (una sola verdad)',
        AsistenciaClase::where('inscripcion_id', $insc1)->whereDate('fecha', $hoy)->where('modalidad', 'unica')->value('estatus') === 'justificada');
} catch (Throwable $e) {
    verificar('La suite corrió hasta el final sin morir', false,
        get_class($e).': '.$e->getMessage().' @ '.basename($e->getFile()).':'.$e->getLine());
} finally {
    $db->rollBack();
}

echo PHP_EOL.'Resultado: '.($verificaciones - $fallidas).' correctas, '.$fallidas.' fallidas'.PHP_EOL;

exit($fallidas === 0 ? 0 : 1);
