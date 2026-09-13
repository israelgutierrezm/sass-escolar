<?php

/**
 * API de la app móvil: las CITAS del lado del DOCENTE. Con rollback.
 *
 * Se corre con `php scripts/prueba-api-docente-citas.php` desde la raíz.
 *
 * ── Qué se vigila ─────────────────────────────────────────────────────────
 *  1. `citas()` trae la agenda: disponibilidad, citas y modalidades.
 *  2. Disponibilidad: agregar una ventana la lista; quitar una ajena → 404.
 *  3. Confirmar sólo una SOLICITADA; rechazar exige motivo.
 *  4. Una cita AJENA → 404 (el alcance lo pone el servicio, no el permiso).
 *  5. Cancelar una confirmada activa; marcar el desenlace sólo si YA pasó.
 *
 * La regla vive en `GestorDeCitas` —la misma que la web y que el lado de la
 * familia—. Las citas se crean directas: el lado del docente sólo mira que la
 * cita sea suya y su estado, no el vínculo (eso es del lado de la familia). El
 * `can:` y el 401/403 del stack los pone el middleware; se comprueban por HTTP.
 */

use App\Http\Controllers\Api\DocenteApiController;
use App\Models\ControlEscolar\Docente;
use App\Models\Familia\Cita;
use App\Models\Familia\DisponibilidadCitaDocente;
use App\Models\Identidad\Persona;
use App\Models\Identidad\Usuario;
use App\Models\Tenant;
use App\Services\Familia\GestorDeCitas;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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

/** Una petición con el usuario resuelto (token) y, si hace falta, cuerpo. */
function req(Usuario $usuario, array $datos = []): Request
{
    $p = Request::create('/', 'POST', $datos);
    $p->setUserResolver(fn () => $usuario);

    return $p;
}

$db->beginTransaction();

try {
    // ── Escenario: un docente con cuenta, y otro para el caso ajeno ──────────
    $personasDocentes = Docente::query()->pluck('persona_id');
    $usuario = Usuario::query()->whereIn('persona_id', $personasDocentes)->first();

    if ($usuario === null) {
        throw new RuntimeException('No hay un docente con cuenta en el demo.');
    }

    auth()->login($usuario);
    $docenteId = (int) $usuario->persona_id;

    $otroDocenteId = (int) ($personasDocentes->first(fn ($p) => (int) $p !== $docenteId) ?? 0);
    $otros = Persona::query()->whereKeyNot($docenteId)->limit(2)->pluck('id')->all();
    $alumnoId = (int) ($otros[0] ?? $docenteId);
    $solicitanteId = (int) ($otros[1] ?? $alumnoId);

    $gestor = app(GestorDeCitas::class);
    $ctrl = app(DocenteApiController::class);

    // Una cita mía en un estado dado, con horas relativas a ahora.
    $crearCita = function (string $estado, int $offsetHoras, ?int $docente = null) use ($docenteId, $alumnoId, $solicitanteId): Cita {
        $inicio = Carbon::now()->addHours($offsetHoras)->startOfHour();

        return Cita::create([
            'docente_persona_id' => $docente ?? $docenteId,
            'alumno_persona_id' => $alumnoId,
            'solicitante_persona_id' => $solicitanteId,
            'inicio' => $inicio,
            'fin' => $inicio->copy()->addMinutes(30),
            'modalidad' => DisponibilidadCitaDocente::PRESENCIAL,
            'motivo' => 'Seguimiento',
            'estado' => $estado,
        ]);
    };

    // ── 1. La agenda ─────────────────────────────────────────────────────────
    echo PHP_EOL.'1. citas(): la agenda del docente'.PHP_EOL;

    $agenda = json_decode($ctrl->citas(req($usuario))->getContent(), true);
    verificar('Trae disponibilidad, citas y modalidades',
        is_array($agenda['disponibilidad'] ?? null) && is_array($agenda['citas'] ?? null) && is_array($agenda['modalidades'] ?? null));

    // ── 2. Disponibilidad ─────────────────────────────────────────────────────
    echo PHP_EOL.'2. Disponibilidad: agregar la propia, no tocar la ajena'.PHP_EOL;

    $ctrl->agregarDisponibilidad(req($usuario, [
        'dia_semana' => 3, 'hora_inicio' => '09:00', 'hora_fin' => '11:00',
        'modalidad' => DisponibilidadCitaDocente::PRESENCIAL, 'duracion_min' => 30, 'lugar' => 'Sala 2',
    ]));
    $agenda = json_decode($ctrl->citas(req($usuario))->getContent(), true);
    $ventana = collect($agenda['disponibilidad'])->firstWhere('lugar', 'Sala 2');
    verificar('La ventana agregada aparece en la agenda', $ventana !== null && ($ventana['duracion_min'] ?? null) === 30);

    $ajena = DisponibilidadCitaDocente::create([
        'persona_id' => $otroDocenteId ?: $alumnoId, 'dia_semana' => 2, 'hora_inicio' => '08:00',
        'hora_fin' => '09:00', 'modalidad' => DisponibilidadCitaDocente::PRESENCIAL, 'duracion_min' => 30,
    ]);
    verificar('Quitar una ventana ajena → 404',
        fallo(fn () => $ctrl->quitarDisponibilidad(req($usuario), $ajena)) === 404);

    if ($ventana !== null) {
        $ctrl->quitarDisponibilidad(req($usuario), DisponibilidadCitaDocente::findOrFail($ventana['id']));
        verificar('Quitar la propia la retira',
            ! DisponibilidadCitaDocente::query()->whereKey($ventana['id'])->exists());
    } else {
        verificar('OMITIDO: no se agregó la ventana', false, 'escenario incompleto');
    }

    // ── 3. Confirmar / rechazar ────────────────────────────────────────────────
    echo PHP_EOL.'3. Confirmar sólo una solicitada; rechazar exige motivo'.PHP_EOL;

    $aConfirmar = $crearCita(Cita::SOLICITADA, 48);
    $ctrl->confirmarCita(req($usuario, ['respuesta' => 'Nos vemos']), $aConfirmar);
    verificar('Confirmar una solicitada la deja confirmada', $aConfirmar->fresh()->estado === Cita::CONFIRMADA);

    verificar('Confirmar una que NO está solicitada → 422',
        fallo(fn () => $ctrl->confirmarCita(req($usuario), $aConfirmar->fresh())) === 422);

    $aRechazar = $crearCita(Cita::SOLICITADA, 72);
    // El guard del motivo vive en el gestor (el required del controlador es su
    // red de UX); se prueba directo para que la mutación lo mate.
    verificar('Rechazar sin motivo → 422',
        fallo(fn () => $gestor->rechazar($aRechazar->fresh(), $docenteId, '   ')) === 422);
    $ctrl->rechazarCita(req($usuario, ['respuesta' => 'Ese día no puedo']), $aRechazar->fresh());
    $aRechazar->refresh();
    verificar('Rechazar con motivo la deja rechazada, con la respuesta', $aRechazar->estado === Cita::RECHAZADA && $aRechazar->respuesta === 'Ese día no puedo');

    // ── 4. Una cita ajena → 404 ────────────────────────────────────────────────
    echo PHP_EOL.'4. Una cita que no es mía → 404'.PHP_EOL;

    if ($otroDocenteId !== 0) {
        $citaAjena = $crearCita(Cita::SOLICITADA, 96, $otroDocenteId);
        verificar('Confirmar una cita ajena → 404 (exigirDocente)',
            fallo(fn () => $ctrl->confirmarCita(req($usuario), $citaAjena)) === 404);
        // cancelar es compartido con la familia: usa «esParte», así que a quien
        // no es ni el docente ni el solicitante le responde 403, no 404.
        verificar('Cancelar una cita ajena → 403 (no eres parte)',
            fallo(fn () => $ctrl->cancelarCita(req($usuario, ['respuesta' => 'x']), $citaAjena)) === 403);
    } else {
        verificar('OMITIDO: no hay un segundo docente', false, 'escenario incompleto');
        verificar('OMITIDO: no hay un segundo docente', false, 'escenario incompleto');
    }

    // ── 5. Cancelar y marcar el desenlace ──────────────────────────────────────
    echo PHP_EOL.'5. Cancelar una activa; marcar sólo lo que ya pasó'.PHP_EOL;

    $aCancelar = $crearCita(Cita::CONFIRMADA, 120);
    $ctrl->cancelarCita(req($usuario, ['respuesta' => 'Surgió un imprevisto']), $aCancelar);
    verificar('Cancelar una confirmada activa la cancela', $aCancelar->fresh()->estado === Cita::CANCELADA);

    $futura = $crearCita(Cita::CONFIRMADA, 24);
    verificar('Marcar una cita que todavía no ocurre → 422',
        fallo(fn () => $ctrl->marcarCita(req($usuario, ['estado' => Cita::REALIZADA]), $futura)) === 422);

    $pasada = $crearCita(Cita::CONFIRMADA, -24);
    $ctrl->marcarCita(req($usuario, ['estado' => Cita::REALIZADA]), $pasada);
    verificar('Marcar una confirmada ya pasada la deja realizada', $pasada->fresh()->estado === Cita::REALIZADA);

    // La serialización que ve la app.
    $serial = $gestor->serializarCita($pasada->fresh());
    verificar('La cita serializada trae alumno, estado y ya_paso',
        array_key_exists('alumno', $serial) && $serial['estado'] === Cita::REALIZADA && $serial['ya_paso'] === true);
} catch (Throwable $e) {
    verificar('La suite corrió hasta el final sin morir', false,
        get_class($e).': '.$e->getMessage().' @ '.basename($e->getFile()).':'.$e->getLine());
} finally {
    $db->rollBack();
}

echo PHP_EOL.'Resultado: '.($verificaciones - $fallidas).' correctas, '.$fallidas.' fallidas'.PHP_EOL;

exit($fallidas === 0 ? 0 : 1);
