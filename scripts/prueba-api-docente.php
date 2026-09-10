<?php

/**
 * API de la app móvil, rebanada 4: el portal del DOCENTE. Con rollback.
 *
 * Se corre con `php scripts/prueba-api-docente.php` desde la raíz.
 *
 * ── Qué se vigila ─────────────────────────────────────────────────────────
 *  1. `OperarComoFaceta:docente` resuelve el rol activo que la API no tenía.
 *  2. `materias()` lista SÓLO las del docente —el alcance sale de la asignación
 *     (`docentes.persona_id`), no del permiso ni de la URL—.
 *  3. `materia()` devuelve el roster de una materia propia; una ajena → 403.
 *
 * El `can:` y el 401/403 del stack HTTP los pone el middleware de la ruta, que
 * no pasa por el controlador; eso se comprueba por HTTP real contra el servidor.
 */

use App\Http\Controllers\Api\DocenteApiController;
use App\Http\Middleware\Api\OperarComoFaceta;
use App\Models\ControlEscolar\AsignaturaGrupo;
use App\Models\Identidad\Rol;
use App\Models\Identidad\Usuario;
use App\Models\Tenant;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
    } catch (HttpException $e) {
        return $e->getStatusCode();
    }

    return null;
}

/** Una petición GET como la de la app, con el usuario ya resuelto (token). */
function comoDocente(Usuario $usuario, array $query = []): Request
{
    $p = Request::create('/', 'GET', $query);
    $p->setUserResolver(fn () => $usuario);

    return $p;
}

/** Corre el middleware `api.faceta` y devuelve lo que dejó en el usuario. */
function pinchar(Usuario $usuario, string $faceta): void
{
    $p = comoDocente($usuario);
    (new OperarComoFaceta)->handle($p, fn ($r) => new Response('', 200), $faceta);
}

$db->beginTransaction();

try {
    // ── Escenario: un docente con una materia asignada y con cuenta ──────────
    $usuario = null;
    $ag = null;
    foreach (AsignaturaGrupo::query()->whereHas('docentes')->with('docentes')->get() as $candidata) {
        foreach ($candidata->docentes as $d) {
            $u = Usuario::query()->where('persona_id', $d->persona_id)->first();
            if ($u !== null) {
                $ag = $candidata;
                $usuario = $u;
                break 2;
            }
        }
    }

    if ($ag === null || $usuario === null) {
        throw new RuntimeException('No hay una materia con un docente con cuenta en el demo.');
    }

    $personaId = (int) $usuario->persona_id;

    // Una materia que NO es suya.
    $ajena = AsignaturaGrupo::query()
        ->whereKeyNot($ag->id)
        ->whereDoesntHave('docentes', fn ($q) => $q->where('docentes.persona_id', $personaId))
        ->first();

    $rolDocente = Rol::query()->where('name', 'docente')->firstOrFail();
    $ctrl = app(DocenteApiController::class);

    // ── 1. El rol activo se resuelve para la API ─────────────────────────────
    echo PHP_EOL.'1. OperarComoFaceta resuelve el rol activo que la API no tenía'.PHP_EOL;

    $rolActivoOriginal = $usuario->rol_activo_id;
    $usuario->forceFill(['rol_activo_id' => null])->save();
    $usuario->refresh();
    verificar('Sin resolver, un rol activo nulo no concede el permiso del docente',
        $usuario->tienePermiso('ver-mis-materias') === false);

    pinchar($usuario, 'docente');
    verificar('Pinchada la faceta, el rol activo queda en el de docente',
        (int) $usuario->rol_activo_id === (int) $rolDocente->id);
    verificar('Y con él concede ver-mis-materias', $usuario->can('ver-mis-materias'));

    // ── 2. El listado de materias, sólo las suyas ────────────────────────────
    echo PHP_EOL.'2. materias(): sólo las del docente, con su roster contado'.PHP_EOL;

    $lista = json_decode($ctrl->materias(comoDocente($usuario))->getContent(), true);
    verificar('materias trae la lista', array_key_exists('materias', $lista) && is_array($lista['materias']));
    $laSuya = collect($lista['materias'])->firstWhere('id', $ag->id);
    verificar('Su materia asignada aparece', $laSuya !== null);
    verificar('Con cuántos alumnos inscritos', $laSuya !== null && ($laSuya['inscritos'] ?? -1) >= 0);
    if ($ajena !== null) {
        verificar('Y NO aparece una materia que no es suya',
            collect($lista['materias'])->firstWhere('id', $ajena->id) === null);
    }

    // ── 3. El detalle de una materia propia: el roster ───────────────────────
    echo PHP_EOL.'3. materia(): la información y los alumnos de una materia propia'.PHP_EOL;

    $det = json_decode($ctrl->materia(comoDocente($usuario), $ag->fresh())->getContent(), true);
    verificar('El detalle nombra la materia', ($det['materia']['id'] ?? null) === $ag->id);
    verificar('Y trae el roster de alumnos', is_array($det['alumnos'] ?? null));
    verificar('El conteo del listado cuadra con el roster del detalle',
        count($det['alumnos']) === ($laSuya['inscritos'] ?? -1));

    // ── 4. El alcance sale de la asignación: una materia ajena → 403 ─────────
    echo PHP_EOL.'4. Una materia que no es suya → 403'.PHP_EOL;

    if ($ajena === null) {
        verificar('OMITIDO: no hay una materia ajena en el demo', false, 'escenario incompleto');
    } else {
        verificar('El detalle de una materia ajena → 403',
            fallo(fn () => $ctrl->materia(comoDocente($usuario), $ajena)) === 403);
    }

    $usuario->forceFill(['rol_activo_id' => $rolActivoOriginal])->save();
} catch (Throwable $e) {
    verificar('La suite corrió hasta el final sin morir', false,
        get_class($e).': '.$e->getMessage().' @ '.basename($e->getFile()).':'.$e->getLine());
} finally {
    $db->rollBack();
}

echo PHP_EOL.'Resultado: '.($verificaciones - $fallidas).' correctas, '.$fallidas.' fallidas'.PHP_EOL;

exit($fallidas === 0 ? 0 : 1);
