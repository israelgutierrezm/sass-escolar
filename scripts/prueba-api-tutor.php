<?php

/**
 * API de la app móvil: el TUTOR EDUCATIVO y sus tutorados. Con rollback.
 *
 * Se corre con `php scripts/prueba-api-tutor.php` desde la raíz.
 *
 * ── Qué se vigila ─────────────────────────────────────────────────────────
 *  1. Mis tutorados salen del VÍNCULO, con su resumen.
 *  2. La ficha ve lo académico y NO lo financiero; abrirla deja rastro (consulta).
 *  3. Anotar una sesión la registra; aparece en la bitácora.
 *  4. Corregir la marca de confidencial de OTRA tutoría → 403.
 *  5. Un alumno que no es mi tutorado → 403.
 *
 * Todo sale de `SeguimientoDeTutorados` (el mismo servicio que la web). El demo
 * no tiene tutorías, así que el escenario se construye en la transacción.
 */

use App\Http\Controllers\Api\TutorApiController;
use App\Models\ControlEscolar\AccesoBitacoraTutoria;
use App\Models\ControlEscolar\Ciclo;
use App\Models\ControlEscolar\SesionTutoria;
use App\Models\ControlEscolar\Tutoria;
use App\Models\Identidad\Persona;
use App\Models\Identidad\Usuario;
use App\Models\Tenant;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
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

function req(Usuario $usuario, array $datos = [], string $metodo = 'POST'): Request
{
    $p = Request::create('/', $metodo, $datos);
    $p->setUserResolver(fn () => $usuario);

    return $p;
}

$db->beginTransaction();

try {
    // ── Escenario: un tutor con cuenta, dos alumnos con matrícula, una tutoría ─
    $alumno = Persona::query()->whereHas('matriculas')->first();
    $otroAlumno = Persona::query()->whereHas('matriculas')->where('id', '!=', $alumno?->id)->first();
    $tutor = Usuario::query()->whereNotNull('persona_id')
        ->whereNotIn('persona_id', array_filter([$alumno?->id, $otroAlumno?->id]))->first();
    $ciclo = Ciclo::query()->value('id');

    if ($alumno === null || $otroAlumno === null || $tutor === null || $ciclo === null) {
        throw new RuntimeException('Faltan datos en el demo (dos alumnos con matrícula, un usuario y un ciclo).');
    }

    auth()->login($tutor);

    $tutoria = Tutoria::create(['tutor_persona_id' => $tutor->persona_id, 'alumno_persona_id' => $alumno->id, 'ciclo_id' => $ciclo, 'activa' => true]);

    $ctrl = app(TutorApiController::class);

    // ── 1. Mis tutorados ─────────────────────────────────────────────────────
    echo PHP_EOL.'1. Mis tutorados salen del vínculo'.PHP_EOL;

    $panorama = json_decode($ctrl->misTutorados(req($tutor, [], 'GET'))->getContent(), true);
    $mio = collect($panorama['tutorados'])->firstWhere('id', $alumno->id);
    verificar('El tutorado aparece', $mio !== null);
    verificar('El resumen cuenta al menos uno', ($panorama['resumen']['total'] ?? 0) >= 1);
    verificar('El tutorado NO trae finanzas (saldo null)', array_key_exists('saldo', $mio['estado']) && $mio['estado']['saldo'] === null);

    // ── 2. La ficha, y su rastro ─────────────────────────────────────────────
    echo PHP_EOL.'2. La ficha es académica y deja rastro al abrirse'.PHP_EOL;

    $consultasAntes = AccesoBitacoraTutoria::query()->where('alumno_persona_id', $alumno->id)->count();
    $ficha = json_decode($ctrl->tutorado(req($tutor, [], 'GET'), $alumno)->getContent(), true);
    verificar('Trae el estado académico sin saldo', array_key_exists('saldo', $ficha['estado']) && $ficha['estado']['saldo'] === null);
    verificar('Trae los catálogos de motivos y modalidades', count($ficha['catalogos']['motivos']) > 0 && count($ficha['catalogos']['modalidades']) > 0);
    verificar('Arranca sin sesiones', $ficha['sesiones'] === []);
    verificar('Abrir la ficha registró una consulta',
        AccesoBitacoraTutoria::query()->where('alumno_persona_id', $alumno->id)->count() === $consultasAntes + 1);

    // ── 3. Anotar una sesión ─────────────────────────────────────────────────
    echo PHP_EOL.'3. Anotar una sesión la registra'.PHP_EOL;

    $r = json_decode($ctrl->registrarSesion(req($tutor, [
        'fecha' => now()->toDateString(), 'modalidad' => 'presencial', 'motivo' => 'seguimiento',
        'tema' => 'Cómo va el semestre', 'acuerdos' => 'Repasar cálculo', 'asistio' => true, 'confidencial' => false,
    ]), $alumno)->getContent(), true);
    verificar('Registrar responde ok', ($r['ok'] ?? null) === true);

    $ficha = json_decode($ctrl->tutorado(req($tutor, [], 'GET'), $alumno)->getContent(), true);
    verificar('La sesión aparece en la bitácora', count($ficha['sesiones']) === 1 && $ficha['sesiones'][0]['tema'] === 'Cómo va el semestre');

    $sesion = SesionTutoria::query()->where('tutoria_id', $tutoria->id)->firstOrFail();

    // ── 4. La marca de confidencial de OTRA tutoría → 403 ────────────────────
    echo PHP_EOL.'4. No se toca la marca de una sesión de otra tutoría'.PHP_EOL;

    $otraTutoria = Tutoria::create(['tutor_persona_id' => $tutor->persona_id, 'alumno_persona_id' => $otroAlumno->id, 'ciclo_id' => $ciclo, 'activa' => true]);
    $sesionAjena = SesionTutoria::create([
        'tutoria_id' => $otraTutoria->id, 'fecha' => now()->toDateString(), 'modalidad' => 'presencial',
        'motivo' => 'seguimiento', 'tema' => 'De otro', 'asistio' => true, 'confidencial' => false,
    ]);
    verificar('Marcar confidencial una sesión de otra tutoría → 403',
        fallo(fn () => $ctrl->marcarConfidencial(req($tutor, ['confidencial' => true], 'PATCH'), $alumno, $sesionAjena)) === 403);

    // La propia sí se puede.
    $ctrl->marcarConfidencial(req($tutor, ['confidencial' => true], 'PATCH'), $alumno, $sesion);
    verificar('La sesión propia sí queda confidencial', $sesion->fresh()->confidencial === true);

    // ── 5. Un alumno que no es mi tutorado → 403 ─────────────────────────────
    echo PHP_EOL.'5. Un alumno ajeno → 403'.PHP_EOL;

    $ajeno = Persona::query()->whereHas('matriculas')
        ->whereNotIn('id', [$alumno->id, $otroAlumno->id])->first();

    if ($ajeno !== null) {
        verificar('La ficha de un alumno que no es mi tutorado → 403',
            fallo(fn () => $ctrl->tutorado(req($tutor, [], 'GET'), $ajeno)) === 403);
    } else {
        verificar('OMITIDO: no hay un tercer alumno', false, 'escenario incompleto');
    }
} catch (Throwable $e) {
    verificar('La suite corrió hasta el final sin morir', false,
        get_class($e).': '.$e->getMessage().' @ '.basename($e->getFile()).':'.$e->getLine());
} finally {
    $db->rollBack();
}

echo PHP_EOL.'Resultado: '.($verificaciones - $fallidas).' correctas, '.$fallidas.' fallidas'.PHP_EOL;

exit($fallidas === 0 ? 0 : 1);
