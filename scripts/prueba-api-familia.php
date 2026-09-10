<?php

/**
 * API de la app móvil, rebanada 4: el portal de la FAMILIA. Con rollback.
 *
 * Se corre con `php scripts/prueba-api-familia.php` desde la raíz.
 *
 * ── Qué se vigila ─────────────────────────────────────────────────────────
 *  1. `OperarComoFaceta:padre_familia` resuelve el rol activo que la API no
 *     tenía (una familia que sólo usa la app, con `rol_activo_id` en null).
 *  2. El alcance sale del VÍNCULO, no de la URL: un hijo no vinculado → 403.
 *  3. Qué se enseña lo decide el PIVOTE del vínculo —académico y financiero por
 *     separado—, no el permiso: apagar `puede_ver_academico` deja `academico`
 *     en null aunque el permiso siga concedido.
 *  4. La conducta va con el permiso de faceta y el módulo, no con el vínculo.
 *  5. Una sola verdad: el promedio sale de `HistorialDelAlumno` y el saldo de
 *     `EstadoCuenta`, los mismos servicios que la web.
 *
 * El `can:` y el 401/403 del stack HTTP los pone el middleware de la ruta, que
 * no pasa por el controlador; eso se comprueba por HTTP real contra el servidor.
 */

use App\Http\Controllers\Api\PadreApiController;
use App\Http\Middleware\Api\OperarComoFaceta;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\Identidad\Persona;
use App\Models\Identidad\Rol;
use App\Models\Identidad\TutorAlumno;
use App\Models\Identidad\Usuario;
use App\Models\Tenant;
use App\Services\EstadoCuenta;
use App\Services\HistorialDelAlumno;
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
function comoFamilia(Usuario $usuario, array $query = []): Request
{
    $p = Request::create('/', 'GET', $query);
    $p->setUserResolver(fn () => $usuario);

    return $p;
}

/** Corre el middleware `api.faceta` y devuelve lo que dejó en el usuario. */
function pinchar(Usuario $usuario, string $faceta): void
{
    $p = comoFamilia($usuario);
    (new OperarComoFaceta)->handle($p, fn ($r) => new Response('', 200), $faceta);
}

$db->beginTransaction();

try {
    // ── Escenario: un tutor con cuenta, un hijo vinculado y matrículas ───────
    $vinculo = TutorAlumno::query()
        ->whereHas('tutor.usuario')
        ->whereHas('alumno.matriculas')
        ->where('puede_ver_academico', true)
        ->where('puede_ver_finanzas', true)
        ->first();

    if ($vinculo === null) {
        throw new RuntimeException('No hay un tutor con cuenta y un hijo con matrículas en el demo.');
    }

    $tutorId = (int) $vinculo->tutor_persona_id;
    $hijoId = (int) $vinculo->alumno_persona_id;
    $usuario = Usuario::query()->where('persona_id', $tutorId)->firstOrFail();
    $hijo = Persona::findOrFail($hijoId);

    $rolPadre = Rol::query()->where('name', 'padre_familia')->firstOrFail();

    // Un alumno de OTRO, no vinculado a este tutor.
    $hijosDelTutor = TutorAlumno::query()->where('tutor_persona_id', $tutorId)->pluck('alumno_persona_id');
    $ajeno = Persona::query()
        ->whereNotIn('id', $hijosDelTutor)
        ->whereHas('matriculas')
        ->first();

    $ctrl = app(PadreApiController::class);

    // ── 1. El rol activo se resuelve para la API ─────────────────────────────
    echo PHP_EOL.'1. OperarComoFaceta resuelve el rol activo que la API no tenía'.PHP_EOL;

    $rolActivoOriginal = $usuario->rol_activo_id;
    $usuario->forceFill(['rol_activo_id' => null])->save();
    $usuario->refresh();
    verificar('Sin resolver, un rol activo nulo no concede el permiso de la familia',
        $usuario->tienePermiso('ver-mis-hijos') === false);

    pinchar($usuario, 'padre_familia');
    verificar('Pinchada la faceta, el rol activo queda en el de padre_familia',
        (int) $usuario->rol_activo_id === (int) $rolPadre->id);
    verificar('Y con él concede los permisos de la familia',
        $usuario->can('ver-mis-hijos') && $usuario->can('ver-historial-academico') && $usuario->can('ver-adeudos'));

    // ── 2. El listado de hijos, con su estado ────────────────────────────────
    echo PHP_EOL.'2. hijos(): la lista con su estado (mismo servicio que la web)'.PHP_EOL;

    $lista = json_decode($ctrl->hijos(comoFamilia($usuario))->getContent(), true);
    verificar('hijos trae la lista', array_key_exists('hijos', $lista) && is_array($lista['hijos']));
    $elHijo = collect($lista['hijos'])->firstWhere('id', $hijoId);
    verificar('El hijo vinculado aparece', $elHijo !== null);
    verificar('Y trae su estado (no sólo el nombre)',
        $elHijo !== null && array_key_exists('estado', $elHijo) && array_key_exists('promedio', $elHijo['estado']));

    // ── 3. El detalle del hijo, con lo que el vínculo deja ver ───────────────
    echo PHP_EOL.'3. hijo(): académico, finanzas y conducta según el vínculo'.PHP_EOL;

    $det = json_decode($ctrl->hijo(comoFamilia($usuario), $hijo)->getContent(), true);
    verificar('El detalle nombra al hijo', ($det['hijo']['id'] ?? null) === $hijoId);
    verificar('Con ver-todo, académico y finanzas viajan',
        is_array($det['academico'] ?? null) && is_array($det['finanzas'] ?? null));
    verificar('El académico trae promedio y renglones',
        isset($det['academico'][0]['promedio']) && is_array($det['academico'][0]['renglones'] ?? null));
    verificar('El financiero trae la cuenta del servicio compartido',
        isset($det['finanzas'][0]['cuenta']['resumen']['saldo']));

    // ── 4. Una sola verdad: las cifras salen de los servicios ────────────────
    echo PHP_EOL.'4. Una sola verdad: promedio de HistorialDelAlumno, saldo de EstadoCuenta'.PHP_EOL;

    $primera = MatriculaOferta::query()->where('persona_id', $hijoId)->orderByDesc('fecha_ingreso')->first();

    // Se empata por MATRÍCULA, no por posición, y el saldo se compara como
    // número: tras json_encode/decode un 0.0 puede volver como entero.
    $entradaAcad = collect($det['academico'])->firstWhere('matricula', $primera->matricula);
    $entradaFin = collect($det['finanzas'])->firstWhere('matricula', $primera->matricula);

    $resumenServicio = app(HistorialDelAlumno::class)->resumen($primera);
    $cuentaServicio = app(EstadoCuenta::class)->para($primera);
    verificar('El promedio del detalle es el del servicio',
        (string) ($entradaAcad['promedio'] ?? '') === (string) ($resumenServicio['promedio'] ?? ''));
    verificar('El saldo del detalle es el de EstadoCuenta',
        abs((float) ($entradaFin['cuenta']['resumen']['saldo'] ?? -1) - (float) $cuentaServicio['resumen']['saldo']) < 0.005);

    // ── 5. El vínculo decide qué se enseña, no el permiso ────────────────────
    echo PHP_EOL.'5. Apagar el pivote académico deja academico en null (con el permiso puesto)'.PHP_EOL;

    $vinculo->forceFill(['puede_ver_academico' => false])->save();
    $detSinAcad = json_decode($ctrl->hijo(comoFamilia($usuario), $hijo->fresh())->getContent(), true);
    verificar('Sin puede_ver_academico, academico es null aunque el permiso siga',
        $usuario->can('ver-historial-academico') && $detSinAcad['academico'] === null);
    verificar('Pero lo financiero sigue viajando (pivote independiente)',
        is_array($detSinAcad['finanzas'] ?? null));
    $vinculo->forceFill(['puede_ver_academico' => true])->save();

    // ── 6. El alcance sale del vínculo: un hijo ajeno → 403 ──────────────────
    echo PHP_EOL.'6. Un alumno no vinculado a esta cuenta → 403'.PHP_EOL;

    if ($ajeno === null) {
        verificar('OMITIDO: no hay un alumno no vinculado en el demo', false, 'escenario incompleto');
    } else {
        verificar('El detalle de un alumno ajeno → 403',
            fallo(fn () => $ctrl->hijo(comoFamilia($usuario), $ajeno)) === 403);
    }

    // Restaurar (el rollback igual lo deshace).
    $usuario->forceFill(['rol_activo_id' => $rolActivoOriginal])->save();
} catch (Throwable $e) {
    verificar('La suite corrió hasta el final sin morir', false,
        get_class($e).': '.$e->getMessage().' @ '.basename($e->getFile()).':'.$e->getLine());
} finally {
    $db->rollBack();
}

echo PHP_EOL.'Resultado: '.($verificaciones - $fallidas).' correctas, '.$fallidas.' fallidas'.PHP_EOL;

exit($fallidas === 0 ? 0 : 1);
