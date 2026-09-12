<?php

/**
 * API de la app móvil: citas familia–docente —ver docentes y ventanas, solicitar
 * y cancelar—. Contra la BD real, con rollback. La regla vive en `GestorDeCitas`
 * (probada a fondo por `prueba-citas-familia-docente.php`); aquí se vigila que la
 * API delegue y que sus guardas de faceta/vínculo respondan.
 *
 * `php scripts/prueba-api-citas-familia.php` desde la raíz.
 */

use App\Http\Controllers\Api\PadreApiController;
use App\Http\Middleware\Api\OperarComoFaceta;
use App\Models\ControlEscolar\AsignaturaGrupo;
use App\Models\ControlEscolar\Inscripcion;
use App\Models\Familia\Cita;
use App\Models\Familia\DisponibilidadCitaDocente;
use App\Models\Identidad\Parentesco;
use App\Models\Identidad\Persona;
use App\Models\Identidad\Rol;
use App\Models\Identidad\TutorAlumno;
use App\Models\Identidad\Usuario;
use App\Models\Tenant;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

tenancy()->initialize(Tenant::find('demo'));

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

function comoFamilia(Usuario $usuario, array $datos = [], string $metodo = 'GET'): Request
{
    $p = Request::create('/', $metodo, $datos);
    $p->setUserResolver(fn () => $usuario);

    return $p;
}

/** Un tutor con cuenta y faceta padre, vinculado a un hijo. */
function tutorDe(int $hijoId, Rol $faceta): Usuario
{
    $persona = Persona::create(['nombre' => 'Tutor', 'primer_apellido' => 'Cita', 'segundo_apellido' => (string) random_int(1000, 9999), 'sexo_id' => 2]);
    $u = Usuario::create([
        'persona_id' => $persona->id,
        'usuario' => 'tutor_cit_'.random_int(100000, 999999),
        'email' => 'tutor_cit_'.random_int(100000, 999999).'@ejemplo.mx',
        'password' => Hash::make('secreto12345'),
        'rol_activo_id' => $faceta->id,
    ]);
    $u->persona->asignacionesRol()->create(['rol_id' => $faceta->id, 'activo' => true]);
    TutorAlumno::create(['tutor_persona_id' => $persona->id, 'alumno_persona_id' => $hijoId, 'parentesco_id' => Parentesco::query()->value('id')]);

    return $u->fresh(['persona', 'rolActivo']);
}

DB::beginTransaction();

try {
    // ── Escenario: un grupo con docente y un alumno inscrito ─────────────────
    $grupo = AsignaturaGrupo::query()->whereHas('docentes')->get()
        ->first(fn (AsignaturaGrupo $ag) => Inscripcion::query()->where('asignatura_grupo_id', $ag->id)
            ->whereDoesntHave('situacion', fn ($q) => $q->where('clave', 'baja'))->exists());

    if ($grupo === null) {
        echo 'Sin un grupo con docente y alumno inscrito; nada que probar.'.PHP_EOL;
        DB::rollBack();
        exit(0);
    }

    $docente = (int) $grupo->docentes->first()->persona_id;
    $alumnoId = (int) Inscripcion::query()->where('asignatura_grupo_id', $grupo->id)
        ->whereDoesntHave('situacion', fn ($q) => $q->where('clave', 'baja'))
        ->with('matriculaOferta:id,persona_id')->get()
        ->map(fn ($i) => $i->matriculaOferta?->persona_id)->filter()->first();
    $hijo = Persona::findOrFail($alumnoId);

    $faceta = Rol::where('name', 'padre_familia')->firstOrFail();
    $faceta->givePermissionTo(['solicitar-citas']);
    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

    $usuario = tutorDe($hijo->id, $faceta);

    // Un hijo AJENO (otra persona no vinculada a este tutor).
    $ajeno = Persona::query()->whereKeyNot($hijo->id)
        ->whereNotIn('id', TutorAlumno::where('tutor_persona_id', $usuario->persona_id)->pluck('alumno_persona_id'))
        ->first();

    // Ventana de atención del docente, en un día futuro.
    $fechaObj = Carbon::now()->addDays(3)->startOfDay();
    $ventana = DisponibilidadCitaDocente::create([
        'persona_id' => $docente, 'dia_semana' => $fechaObj->isoWeekday(),
        'hora_inicio' => '09:00', 'hora_fin' => '11:00', 'modalidad' => 'presencial', 'duracion_min' => 20,
    ]);
    $fecha = $fechaObj->format('Y-m-d');

    auth()->login($usuario);
    (new OperarComoFaceta)->handle(comoFamilia($usuario), fn ($r) => new Response('', 200), 'padre_familia');

    $ctrl = app(PadreApiController::class);

    echo PHP_EOL.'0. La ficha del hijo expone puede_citas'.PHP_EOL;
    $ficha = json_decode($ctrl->hijo(comoFamilia($usuario), $hijo->fresh())->getContent(), true);
    verificar('puede_citas es true con el permiso de la faceta', ($ficha['puede_citas'] ?? null) === true);

    echo PHP_EOL.'1. Leer: docentes con ventanas, modalidades y citas'.PHP_EOL;

    $api = json_decode($ctrl->citas(comoFamilia($usuario), $hijo->fresh())->getContent(), true);
    $suDocente = collect($api['docentes'])->firstWhere('persona_id', $docente);
    verificar('Trae al docente del hijo', $suDocente !== null);
    verificar('Con sus ventanas de atención', ! empty($suDocente['ventanas'] ?? []));
    verificar('Y las modalidades', ! empty($api['modalidades']));
    verificar('Sin citas todavía', $api['citas'] === []);

    echo PHP_EOL.'2. Solicitar una cita en un hueco válido'.PHP_EOL;

    $ctrl->solicitarCita(comoFamilia($usuario, [
        'disponibilidad_id' => $ventana->id, 'fecha' => $fecha, 'hora_inicio' => '09:00', 'motivo' => 'Avance de mi hijo',
    ], 'POST'), $hijo->fresh());
    $cita = Cita::where('alumno_persona_id', $hijo->id)->where('solicitante_persona_id', $usuario->persona_id)->first();
    verificar('Queda una cita SOLICITADA', $cita !== null && $cita->estado === Cita::SOLICITADA);
    $api = json_decode($ctrl->citas(comoFamilia($usuario), $hijo->fresh())->getContent(), true);
    verificar('Y aparece en la lista de citas', collect($api['citas'])->contains(fn ($c) => $c['id'] === $cita->id));

    echo PHP_EOL.'3. Guardas de solicitar (delegadas al servicio)'.PHP_EOL;

    verificar('Sin motivo → 422',
        fallo(fn () => $ctrl->solicitarCita(comoFamilia($usuario, ['disponibilidad_id' => $ventana->id, 'fecha' => $fecha, 'hora_inicio' => '09:20'], 'POST'), $hijo->fresh())) === 422);
    verificar('Una hora que no es hueco (09:05) → 422',
        fallo(fn () => $ctrl->solicitarCita(comoFamilia($usuario, ['disponibilidad_id' => $ventana->id, 'fecha' => $fecha, 'hora_inicio' => '09:05', 'motivo' => 'x'], 'POST'), $hijo->fresh())) === 422);

    echo PHP_EOL.'4. Cancelar la cita'.PHP_EOL;

    $ctrl->cancelarCita(comoFamilia($usuario, ['respuesta' => 'Ya no puedo'], 'POST'), $cita->fresh());
    verificar('La cita queda CANCELADA', $cita->fresh()->estado === Cita::CANCELADA);

    echo PHP_EOL.'5. El vínculo cierra a quién'.PHP_EOL;

    if ($ajeno !== null) {
        verificar('Ver las citas de un hijo ajeno → 404',
            fallo(fn () => $ctrl->citas(comoFamilia($usuario), $ajeno)) === 404);
        verificar('Solicitar por un hijo ajeno → 404',
            fallo(fn () => $ctrl->solicitarCita(comoFamilia($usuario, ['disponibilidad_id' => $ventana->id, 'fecha' => $fecha, 'hora_inicio' => '09:20', 'motivo' => 'x'], 'POST'), $ajeno)) === 404);
    }

    // Otra familia (no parte de la cita) no puede cancelarla.
    $c2 = app(\App\Services\Familia\GestorDeCitas::class)->solicitar($usuario->persona_id, $hijo->id, $ventana->id, $fecha, '09:40', 'Otra');
    $otro = tutorDe($ajeno?->id ?? $hijo->id, $faceta); // un tutor distinto
    verificar('Cancelar una cita de la que no eres parte → 403',
        fallo(fn () => $ctrl->cancelarCita(comoFamilia($otro, ['respuesta' => 'no'], 'POST'), $c2->fresh())) === 403);
} catch (Throwable $e) {
    verificar('La suite corrió hasta el final sin morir', false,
        get_class($e).': '.$e->getMessage().' @ '.basename($e->getFile()).':'.$e->getLine());
} finally {
    DB::rollBack();
}

echo PHP_EOL.'Resultado: '.($verificaciones - $fallidas).' correctas, '.$fallidas.' fallidas'.PHP_EOL;

exit($fallidas === 0 ? 0 : 1);
