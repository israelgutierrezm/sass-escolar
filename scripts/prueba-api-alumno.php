<?php

/**
 * API de la app móvil, rebanada 3: los datos del alumno. Con rollback.
 *
 * Se corre con `php scripts/prueba-api-alumno.php` desde la raíz.
 *
 * ── Qué se vigila ─────────────────────────────────────────────────────────
 *  1. **El rol activo se RESUELVE para la API.** `OperarComoFaceta` fija la
 *     faceta del portal aunque `rol_activo_id` esté en null (un alumno que sólo
 *     usa la app). Sin él, todo `can:` falla cerrado.
 *  2. **Fija la faceta del PORTAL, no «el primer rol».** Quien es alumno Y
 *     administrativo opera como ALUMNO en el portal del alumno: su ámbito es
 *     `alumno`, no `escuela`. Es lo que impide que su estado de cuenta muestre
 *     la cartera de toda la escuela.
 *  3. **Fija en MEMORIA, sin persistir**: no le cambia a la web su rol activo.
 *  4. **Quien no tiene la faceta, 403.**
 *  5. **El alcance sale del token, no de la URL**: la materia, el historial y
 *     el estado de cuenta de otro no se alcanzan cambiando el id/parámetro.
 *  6. **Reusa los servicios de la web** (una sola verdad): el listado/detalle
 *     de materias, el historial y el estado de cuenta salen de los mismos
 *     `CursosDelAlumno`/`HistorialDelAlumno`/`EstadoCuenta`.
 *
 * El `can:` y el 401/403 del stack HTTP los cubre el middleware de la ruta, que
 * no pasa por el controlador; se comprobó por HTTP real contra el servidor.
 */

use App\Http\Controllers\Api\AlumnoApiController;
use App\Http\Controllers\Api\AvisosApiController;
use App\Http\Middleware\Api\OperarComoFaceta;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\ControlEscolar\Inscripcion;
use App\Models\Identidad\PersonaRol;
use App\Models\Identidad\Rol;
use App\Models\Identidad\Usuario;
use App\Models\Plataforma\Aviso;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Contracts\Console\Kernel;
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
function comoAlumno(Usuario $usuario, array $query = []): Request
{
    $p = Request::create('/', 'GET', $query);
    $p->setUserResolver(fn () => $usuario);

    return $p;
}

/** Corre el middleware `api.faceta` y devuelve lo que dejó en el usuario. */
function pinchar(Usuario $usuario, string $faceta): void
{
    $p = comoAlumno($usuario);
    (new OperarComoFaceta)->handle($p, fn ($r) => new Response('', 200), $faceta);
}

$db->beginTransaction();

try {
    // ── Escenario: un alumno con DOS matrículas y una inscripción viva ───────
    $conDos = MatriculaOferta::query()->select('persona_id')
        ->groupBy('persona_id')->havingRaw('count(*) >= 2')->pluck('persona_id');

    $insc = Inscripcion::query()
        ->whereHas('asignaturaGrupo')
        ->whereHas('situacion', fn ($q) => $q->where('clave', '!=', 'baja'))
        ->whereHas('matriculaOferta', fn ($q) => $q->whereIn('persona_id', $conDos))
        ->with('matriculaOferta')
        ->latest('id')->first();

    if ($insc === null) {
        throw new RuntimeException('No hay un alumno con dos matrículas y una inscripción viva en el demo.');
    }

    $personaId = (int) $insc->matriculaOferta->persona_id;
    $usuario = Usuario::query()->where('persona_id', $personaId)->firstOrFail();
    $agPropia = (int) $insc->asignatura_grupo_id;

    $misMatriculas = MatriculaOferta::query()->where('persona_id', $personaId)->orderBy('matricula')->get();
    $matPropia1 = (int) $misMatriculas[0]->id;
    $matPropia2 = (int) $misMatriculas[1]->id;

    $matAjena = (int) MatriculaOferta::query()->where('persona_id', '!=', $personaId)->value('id');
    $agAjena = (int) Inscripcion::query()
        ->whereHas('matriculaOferta', fn ($q) => $q->where('persona_id', '!=', $personaId))
        ->value('asignatura_grupo_id');

    $rolAlumno = Rol::query()->where('name', 'alumno')->firstOrFail();
    $rolAdmin = Rol::query()->where('name', 'administrativo')->firstOrFail();

    $alumnoCtrl = app(AlumnoApiController::class);
    $avisosCtrl = app(AvisosApiController::class);

    // ── 1. El rol activo se resuelve para la API ─────────────────────────────
    echo PHP_EOL.'1. OperarComoFaceta resuelve el rol activo que la API no tenía'.PHP_EOL;

    // Se deja `rol_activo_id` en NULL en la base: es el alumno que sólo usa la
    // app y nunca entró a la web. Sin el middleware, `can()` fallaría cerrado.
    $rolActivoOriginal = $usuario->rol_activo_id;
    $usuario->forceFill(['rol_activo_id' => null])->save();
    $usuario->refresh();
    verificar('Sin resolver, un rol activo nulo no concede el permiso del alumno',
        $usuario->tienePermiso('ver-mis-cursos') === false);

    pinchar($usuario, 'alumno');
    verificar('Pinchada la faceta, el rol activo queda en el de alumno',
        (int) $usuario->rol_activo_id === (int) $rolAlumno->id);
    verificar('Y con él sí concede los permisos del alumno',
        $usuario->can('ver-mis-cursos') && $usuario->can('ver-historial-academico') && $usuario->can('ver-adeudos'));

    // ── 2. En MEMORIA, sin persistir ─────────────────────────────────────────
    echo PHP_EOL.'2. Fija en memoria, sin tocar la base (aislado de la web)'.PHP_EOL;

    $enBase = (int) ($db->table('usuarios')->where('id', $usuario->id)->value('rol_activo_id') ?? 0);
    verificar('La base sigue con el rol activo que tenía, no el pinchado',
        $enBase === 0); // lo dejamos en null arriba; el pinchado no lo guardó

    // ── 3. Fija la faceta del PORTAL, no «el primer rol» ─────────────────────
    echo PHP_EOL.'3. Alumno Y administrativo: en el portal del alumno opera como alumno'.PHP_EOL;

    PersonaRol::query()->create(['persona_id' => $personaId, 'rol_id' => $rolAdmin->id, 'activo' => true]);
    $usuario->unsetRelation('persona'); // para que rolesDisponibles vea el rol nuevo
    verificar('La persona ahora tiene las dos facetas',
        $usuario->rolesDisponibles()->pluck('name')->contains('administrativo')
        && $usuario->rolesDisponibles()->pluck('name')->contains('alumno'));

    pinchar($usuario, 'alumno');
    verificar('El rol pinchado es el de alumno, no el administrativo',
        (int) $usuario->rol_activo_id === (int) $rolAlumno->id);
    verificar('Su ámbito es alumno (no escuela): el estado de cuenta será el SUYO',
        $usuario->rolActivo->ambitoDePermisos() === 'alumno');

    // ── 4. Quien no tiene la faceta, 403 ─────────────────────────────────────
    echo PHP_EOL.'4. Sin la faceta pedida, se rehúsa'.PHP_EOL;

    // Un administrativo puro (el demo tiene varios): sin faceta alumno.
    $soloAdmin = Usuario::query()->whereHas('persona', fn ($q) => $q
        ->whereHas('rolesActivos', fn ($r) => $r->where('name', 'administrativo'))
        ->whereDoesntHave('rolesActivos', fn ($r) => $r->where('name', 'alumno')))
        ->firstOrFail();

    verificar('Un administrativo puro que pega al portal del alumno → 403',
        fallo(fn () => pinchar($soloAdmin, 'alumno')) === 403);

    // ── 5. Los datos del alumno, reusando los servicios de la web ────────────
    echo PHP_EOL.'5. Materias, historial y estado de cuenta (una sola verdad)'.PHP_EOL;

    pinchar($usuario, 'alumno');

    $materias = json_decode($alumnoCtrl->materias(comoAlumno($usuario))->getContent(), true);
    verificar('materias trae ciclos y pendientes', array_key_exists('ciclos', $materias) && array_key_exists('pendientes', $materias));
    $agsListados = collect($materias['ciclos'])->flatMap(fn ($c) => collect($c['cursos'])->pluck('id'));
    verificar('Su materia viva aparece en el listado', $agsListados->contains($agPropia));

    $detalle = json_decode($alumnoCtrl->materia(comoAlumno($usuario), $agPropia)->getContent(), true);
    verificar('El detalle de su materia trae curso, evaluación y asistencia',
        isset($detalle['curso']) && isset($detalle['evaluacion']) && isset($detalle['asistencia']));

    verificar('La materia de OTRO → 403',
        fallo(fn () => $alumnoCtrl->materia(comoAlumno($usuario), $agAjena)) === 403);

    // ── 6. El historial y su selector de matrícula (alcance por token) ───────
    echo PHP_EOL.'6. Historial: la matrícula se elige de entre las SUYAS'.PHP_EOL;

    $h2 = json_decode($alumnoCtrl->historial(comoAlumno($usuario, ['matricula' => $matPropia2]))->getContent(), true);
    verificar('Pidiendo su segunda matrícula, devuelve ESA', (int) $h2['matricula']['id'] === $matPropia2);
    verificar('Y trae renglones y resumen del servicio compartido',
        is_array($h2['renglones']) && array_key_exists('promedio', $h2['resumen']));

    $hAjena = json_decode($alumnoCtrl->historial(comoAlumno($usuario, ['matricula' => $matAjena]))->getContent(), true);
    verificar('Pidiendo la matrícula de OTRO, cae en la PROPIA (no la ajena)',
        (int) $hAjena['matricula']['id'] !== $matAjena
        && in_array((int) $hAjena['matricula']['id'], [$matPropia1, $matPropia2], true));

    // ── 7. El estado de cuenta, mismo alcance y mismo servicio ───────────────
    echo PHP_EOL.'7. Estado de cuenta: alcance propio y servicio compartido'.PHP_EOL;

    $ec = json_decode($alumnoCtrl->estadoCuenta(comoAlumno($usuario, ['matricula' => $matPropia1]))->getContent(), true);
    verificar('Devuelve su matrícula y la cuenta armada por EstadoCuenta',
        (int) $ec['matricula']['id'] === $matPropia1 && isset($ec['cuenta']['resumen']['saldo']));

    $ecAjena = json_decode($alumnoCtrl->estadoCuenta(comoAlumno($usuario, ['matricula' => $matAjena]))->getContent(), true);
    verificar('La cuenta de OTRO no se alcanza cambiando el parámetro',
        (int) $ecAjena['matricula']['id'] !== $matAjena);

    // ── 8. Avisos: por persona, con su contador ──────────────────────────────
    echo PHP_EOL.'8. Avisos: los suyos, y confirmar el ajeno se rehúsa'.PHP_EOL;

    $av = json_decode($avisosCtrl->index(comoAlumno($usuario))->getContent(), true);
    verificar('avisos trae la lista y el contador sin_leer',
        array_key_exists('avisos', $av) && array_key_exists('sin_leer', $av));

    // Confirmar un aviso que NO le llega → 404. Se fabrica uno dirigido a un rol
    // que la persona no tiene forma de recibir por otra vía.
    $avAjeno = Aviso::query()->create([
        'titulo' => 'ZZ ajeno', 'cuerpo' => 'x', 'prioridad' => 'informativo', 'publicado' => false,
        'publicado_desde' => now()->subDay(), 'vigente_hasta' => now()->addDay(),
    ]);
    // Sin destinos y no publicado → no le llega a nadie.
    verificar('Confirmar un aviso que no le toca → 404',
        fallo(fn () => $avisosCtrl->confirmar(comoAlumno($usuario), $avAjeno)) === 404);

    // Restaurar el rol activo original para no dejar rastro conceptual (rollback igual lo deshace).
    $usuario->forceFill(['rol_activo_id' => $rolActivoOriginal])->save();
} catch (Throwable $e) {
    verificar('La suite corrió hasta el final sin morir', false,
        get_class($e).': '.$e->getMessage().' @ '.basename($e->getFile()).':'.$e->getLine());
} finally {
    $db->rollBack();
}

echo PHP_EOL.'Resultado: '.($verificaciones - $fallidas).' correctas, '.$fallidas.' fallidas'.PHP_EOL;

exit($fallidas === 0 ? 0 : 1);
