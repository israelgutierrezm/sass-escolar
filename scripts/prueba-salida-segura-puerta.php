<?php

/**
 * Salida segura, rebanada 2: la PUERTA. Con rollback.
 *
 * Se corre con `php scripts/prueba-salida-segura-puerta.php` desde la raíz.
 *
 * ── Qué se vigila, y por qué ──────────────────────────────────────────────
 *  1. **El token del QR lo pone el SERVIDOR y sólo en autorizaciones.** No viene
 *     del cliente (el fillable lo ignora) y un bloqueo no recibe token.
 *  2. **Se valida contra el estado ACTUAL, no contra el papel.** Un QR de quien
 *     la escuela bloqueó DESPUÉS se rechaza; uno vencido, también; y el token de
 *     un alumno no sirve para otro.
 *  3. **Dos caminos, mismo servidor decide**: por QR (tercero) o de la lista
 *     (tutor/autorizado con cuenta). Un desconocido o un bloqueado, 422.
 *  4. **El registro anota lo correcto** (cómo, quién, la fila usada) y **avisa a
 *     la familia** (destino Alumno + el modificador Familiares).
 *  5. **El QR vigente se puede reusar**: no es de un solo uso.
 */

use App\Enums\DestinoEvento;
use App\Enums\PrioridadAviso;
use App\Http\Controllers\SalidaSeguraController;
use App\Models\Identidad\AutorizadoRecoger;
use App\Models\Identidad\Persona;
use App\Models\Identidad\SalidaAlumno;
use App\Models\Identidad\TutorAlumno;
use App\Models\Identidad\Usuario;
use App\Models\Plataforma\Aviso;
use App\Models\Tenant;
use App\Services\Familia\PuedeRecoger;
use App\Services\Familia\RegistradorDeSalida;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\RedirectResponse;
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

    echo ($ok ? "  \033[32mOK\033[39m   " : "  \033[31mFALLA\033[39m ").$que
        .($detalle !== '' ? "  [{$detalle}]" : '').PHP_EOL;
}

function fallo(callable $accion): ?int
{
    try {
        $accion();
    } catch (HttpException $e) {
        return $e->getStatusCode();
    } catch (ValidationException) {
        return 422;
    }

    return null;
}

function peticionDe(Usuario $usuario, array $datos = []): Request
{
    $p = Request::create('/', 'POST', $datos);
    $p->setUserResolver(fn () => $usuario);

    return $p;
}

const PREF = 'ZZPUE-';

$db->beginTransaction();

try {
    $reglas = app(PuedeRecoger::class);
    $control = app(SalidaSeguraController::class);
    $registrador = app(RegistradorDeSalida::class);
    $admin = Usuario::query()->where('usuario', 'demo')->firstOrFail();

    $vinculo = TutorAlumno::query()->first();
    if ($vinculo === null) {
        echo 'Sin vínculos familiares; nada que probar.'.PHP_EOL;
        $db->rollBack();
        exit(0);
    }

    $alumno = Persona::query()->findOrFail($vinculo->alumno_persona_id);
    $tutor1 = Persona::query()->findOrFail($vinculo->tutor_persona_id);
    // Un tercero CON cuenta (para bloquear su token) y un DESCONOCIDO (que además
    // hace de "otro alumno").
    $x = Persona::query()->whereKeyNot($alumno->id)->whereKeyNot($tutor1->id)->firstOrFail();
    $u = Persona::query()->whereKeyNot($alumno->id)->whereKeyNot($tutor1->id)->whereKeyNot($x->id)->firstOrFail();

    // ── 1. El token lo pone el servidor, sólo en autorizaciones ─────────────
    echo PHP_EOL.'1. El token es del servidor y sólo de las autorizaciones'.PHP_EOL;

    $terceroX = AutorizadoRecoger::create(['alumno_persona_id' => $alumno->id, 'persona_id' => $x->id, 'nombre' => PREF.'Tio', 'permitido' => true]);
    $terceroS = AutorizadoRecoger::create(['alumno_persona_id' => $alumno->id, 'persona_id' => null, 'nombre' => PREF.'Abuela', 'permitido' => true]);
    $terceroV = AutorizadoRecoger::create(['alumno_persona_id' => $alumno->id, 'persona_id' => null, 'nombre' => PREF.'Vencido', 'permitido' => true, 'vigencia_hasta' => now()->subDay()->toDateString()]);

    verificar('Una autorización recibe token del servidor', is_string($terceroX->token) && strlen($terceroX->token) >= 32);
    verificar('El token no viene del cliente (fillable lo ignora)',
        AutorizadoRecoger::create(['alumno_persona_id' => $alumno->id, 'persona_id' => null, 'nombre' => PREF.'Hack', 'permitido' => true, 'token' => 'HACKEADO'])->token !== 'HACKEADO');

    $bloqueoSuelto = AutorizadoRecoger::create(['alumno_persona_id' => $alumno->id, 'persona_id' => $u->id, 'nombre' => PREF.'Bloq', 'permitido' => false, 'motivo' => 'Custodia']);
    verificar('Un bloqueo NO recibe token', $bloqueoSuelto->token === null);

    // ── 2. Registro por QR de un tercero sin cuenta ─────────────────────────
    echo PHP_EOL.'2. Por QR: registra lo correcto y avisa a la familia'.PHP_EOL;

    $avisosAntes = (int) (Aviso::query()->max('id') ?? 0);

    $salida = $registrador->registrar($alumno, ['token' => $terceroS->token], $admin);
    verificar('Registró por QR (como = qr)', $salida->como === SalidaAlumno::POR_QR);
    verificar('recogido_por en null (tercero sin cuenta)', $salida->recogido_por_persona_id === null);
    verificar('recogido_nombre = el del autorizado', $salida->recogido_nombre === PREF.'Abuela');
    verificar('autorizado_id apunta a la fila usada', $salida->autorizado_id === $terceroS->id);
    verificar('El alumno de la salida es el correcto', (int) $salida->alumno_persona_id === $alumno->id);

    $aviso = Aviso::query()->where('id', '>', $avisosAntes)->where('titulo', 'like', 'Salida registrada%')->latest('id')->first();
    verificar('Se creó el aviso de salida', $aviso !== null);
    verificar('El aviso es Importante', $aviso?->prioridad === PrioridadAviso::Importante);
    verificar('El cuerpo nombra a quien recogió', is_string($aviso?->cuerpo) && str_contains($aviso->cuerpo, PREF.'Abuela'));

    $destinos = $aviso ? $aviso->destinos()->get() : collect();
    verificar('Va dirigido al alumno',
        $destinos->contains(fn ($d) => $d->tipo === DestinoEvento::Alumno && (int) $d->destino_id === $alumno->id));
    verificar('Y a su familia (modificador Familiares)',
        $destinos->contains(fn ($d) => $d->tipo === DestinoEvento::Familiares && $d->destino_id === null));

    // ── 3. Registro de la lista: un tutor ───────────────────────────────────
    echo PHP_EOL.'3. De la lista: un tutor recoge por su vínculo'.PHP_EOL;

    $salidaT = $registrador->registrar($alumno, ['persona_id' => $tutor1->id], $admin);
    verificar('El tutor registra como = tutor', $salidaT->como === SalidaAlumno::POR_TUTOR);
    verificar('recogido_por = el tutor', (int) $salidaT->recogido_por_persona_id === $tutor1->id);
    verificar('recogido_nombre = el nombre del tutor', $salidaT->recogido_nombre === $tutor1->nombreCompleto());
    verificar('autorizado_id en null por la vía manual', $salidaT->autorizado_id === null);

    // ── 4. Se valida contra el estado ACTUAL ────────────────────────────────
    echo PHP_EOL.'4. El QR se valida contra el estado de HOY'.PHP_EOL;

    // El bloqueo de custodia sobre X gana sobre su propio token, emitido antes.
    AutorizadoRecoger::create(['alumno_persona_id' => $alumno->id, 'persona_id' => $x->id, 'nombre' => PREF.'TioBloq', 'permitido' => false, 'motivo' => 'Medida cautelar']);
    verificar('El QR de alguien bloqueado DESPUÉS se rechaza (422)',
        fallo(fn () => $registrador->registrar($alumno, ['token' => $terceroX->token], $admin)) === 422);
    verificar('persona_id de un bloqueado → 422',
        fallo(fn () => $registrador->registrar($alumno, ['persona_id' => $x->id], $admin)) === 422);
    verificar('Un QR VENCIDO → 422',
        fallo(fn () => $registrador->registrar($alumno, ['token' => $terceroV->token], $admin)) === 422);
    verificar('El token de un alumno no sirve para otro → 422',
        fallo(fn () => $registrador->registrar($u, ['token' => $terceroS->token], $admin)) === 422);
    verificar('persona_id de un desconocido → 422',
        fallo(fn () => $registrador->registrar($alumno, ['persona_id' => $u->id], $admin)) === 422);
    verificar('Sin QR ni persona → 422',
        fallo(fn () => $registrador->registrar($alumno, [], $admin)) === 422);

    // ── 5. El controlador registra y redirige; el QR vigente se reusa ───────
    echo PHP_EOL.'5. El controlador de la puerta, y el QR reusable'.PHP_EOL;

    $antes = SalidaAlumno::query()->count();
    $resp = $control->registrar(peticionDe($admin, ['persona_id' => $tutor1->id]), $alumno, $registrador);
    verificar('El controlador registra y redirige',
        $resp instanceof RedirectResponse && SalidaAlumno::query()->count() === $antes + 1);

    $dup = $registrador->registrar($alumno, ['token' => $terceroS->token], $admin);
    verificar('El QR vigente se puede reusar (no es de un solo uso)', $dup->como === SalidaAlumno::POR_QR);

    verificar('El permiso de la puerta lo tiene quien lo debe tener', $admin->can('registrar-salida-alumno'));
} catch (Throwable $e) {
    verificar('La suite corrió hasta el final sin morir', false,
        get_class($e).': '.$e->getMessage().' @ '.basename($e->getFile()).':'.$e->getLine());
} finally {
    $db->rollBack();
}

echo PHP_EOL.'Resultado: '.($verificaciones - $fallidas).' correctas, '.$fallidas.' fallidas'.PHP_EOL;

exit($fallidas === 0 ? 0 : 1);
