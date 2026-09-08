<?php

/**
 * Citas familia–docente. Con rollback. Ver `docs/plan-citas-familia-docente.md`.
 *
 * Se corre con `php scripts/prueba-citas-familia-docente.php` desde la raíz.
 *
 * ── Qué se vigila, y por qué ──────────────────────────────────────────────
 *  1. **El vínculo se DERIVA**: `esHijoDe` (tutores_alumno) y `daClaseA`
 *     (docente_asignatura_grupo vía inscripción). Lo ajeno, 404/403.
 *  2. **El hueco es válido**: cae en el día de la ventana, dentro del rango y en
 *     un múltiplo de la duración; no en el pasado.
 *  3. **No se encima**: al pedir, un hueco ya CONFIRMADO se rehúsa; y al
 *     confirmar, bajo bloqueo, dos horas encimadas no pasan las dos.
 *  4. **La máquina de estados** comprueba origen y parte: confirmar/rechazar sólo
 *     una solicitada, marcar sólo una confirmada ya pasada, cancelar sólo activa.
 *  5. **Avisa** a la parte que corresponde por el canal de avisos.
 */

use App\Enums\DestinoEvento;
use App\Http\Controllers\CitaFamiliaController;
use App\Http\Controllers\DocenciaCitasController;
use App\Models\ControlEscolar\AsignaturaGrupo;
use App\Models\ControlEscolar\Docente;
use App\Models\ControlEscolar\Inscripcion;
use App\Models\Familia\Cita;
use App\Models\Familia\DisponibilidadCitaDocente;
use App\Models\Identidad\Parentesco;
use App\Models\Identidad\Persona;
use App\Models\Identidad\TutorAlumno;
use App\Models\Identidad\Usuario;
use App\Models\Plataforma\Aviso;
use App\Models\Tenant;
use App\Services\Familia\GestorDeCitas;
use App\Support\CatalogoPermisos;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
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

const PREF = 'ZZCITA-';

$db->beginTransaction();

try {
    $gestor = app(GestorDeCitas::class);

    // ── Escenario: un grupo con docente y DOS alumnos ───────────────────────
    $grupo = AsignaturaGrupo::query()->whereHas('docentes')->get()
        ->first(function (AsignaturaGrupo $ag) {
            $n = Inscripcion::query()->where('asignatura_grupo_id', $ag->id)
                ->whereDoesntHave('situacion', fn ($q) => $q->where('clave', 'baja'))
                ->with('matriculaOferta:id,persona_id')->get()
                ->map(fn ($i) => $i->matriculaOferta?->persona_id)->filter()->unique();

            return $n->count() >= 2;
        });

    if ($grupo === null) {
        echo 'Sin un grupo con docente y dos alumnos; nada que probar.'.PHP_EOL;
        $db->rollBack();
        exit(0);
    }

    $docente = (int) $grupo->docentes->first()->persona_id;
    $alumnos = Inscripcion::query()->where('asignatura_grupo_id', $grupo->id)
        ->whereDoesntHave('situacion', fn ($q) => $q->where('clave', 'baja'))
        ->with('matriculaOferta:id,persona_id')->get()
        ->map(fn ($i) => (int) $i->matriculaOferta?->persona_id)->filter()->unique()->values();
    $a1 = $alumnos[0];
    $a2 = $alumnos[1];

    $parentesco = (int) Parentesco::query()->value('id');
    $usados = [$docente, $a1, $a2];
    $spares = Persona::query()->whereNotIn('id', $usados)->limit(3)->pluck('id')->all();
    [$t1, $t2, $dxPersona] = $spares;

    TutorAlumno::create(['tutor_persona_id' => $t1, 'alumno_persona_id' => $a1, 'parentesco_id' => $parentesco, 'puede_ver_academico' => true, 'puede_ver_finanzas' => true]);
    TutorAlumno::create(['tutor_persona_id' => $t2, 'alumno_persona_id' => $a2, 'parentesco_id' => $parentesco, 'puede_ver_academico' => true, 'puede_ver_finanzas' => true]);

    // Un docente que NO le da clase al alumno (para el 403).
    $situacionDoc = Docente::query()->where('persona_id', $docente)->value('situacion_id');
    Docente::create(['persona_id' => $dxPersona, 'clave_profesor' => PREF.'X', 'situacion_id' => $situacionDoc]);

    // ── 1. Las derivaciones del vínculo ─────────────────────────────────────
    echo PHP_EOL.'1. El vínculo se deriva, no se guarda'.PHP_EOL;

    verificar('El docente le da clase a su alumno', $gestor->daClaseA($docente, $a1));
    verificar('El docente ajeno NO le da clase', ! $gestor->daClaseA($dxPersona, $a1));
    verificar('El tutor 1 es del alumno 1', $gestor->esHijoDe($t1, $a1));
    verificar('El tutor 1 NO es del alumno 2', ! $gestor->esHijoDe($t1, $a2));

    $docentes = $gestor->docentesDelAlumno($a1);
    verificar('Los docentes del alumno incluyen al docente, con sus materias',
        collect($docentes)->firstWhere('persona_id', $docente) !== null
        && count(collect($docentes)->firstWhere('persona_id', $docente)['materias']) > 0);

    // ── 2. Ventanas y solicitud ─────────────────────────────────────────────
    echo PHP_EOL.'2. La familia solicita en un hueco válido'.PHP_EOL;

    $fechaObj = Carbon::now()->addDays(3)->startOfDay();
    $dia = $fechaObj->isoWeekday();
    $fecha = $fechaObj->format('Y-m-d');

    $ventana = DisponibilidadCitaDocente::create(['persona_id' => $docente, 'dia_semana' => $dia, 'hora_inicio' => '09:00', 'hora_fin' => '11:00', 'modalidad' => 'presencial', 'duracion_min' => 20]);
    $ventanaDx = DisponibilidadCitaDocente::create(['persona_id' => $dxPersona, 'dia_semana' => $dia, 'hora_inicio' => '09:00', 'hora_fin' => '10:00', 'modalidad' => 'presencial', 'duracion_min' => 20]);

    verificar('Las ventanas del docente se listan', $gestor->ventanasDe($docente)->count() === 1);

    $avisosAntes = (int) (Aviso::query()->max('id') ?? 0);

    $c1 = $gestor->solicitar($t1, $a1, $ventana->id, $fecha, '09:00', PREF.'avance');
    verificar('Se creó la cita solicitada', $c1->estado === Cita::SOLICITADA);
    verificar('El fin lo calculó el servidor (20 min)', $c1->inicio->copy()->addMinutes(20)->equalTo($c1->fin));
    verificar('Hereda la modalidad de la ventana', $c1->modalidad === 'presencial');

    $aviso = Aviso::query()->where('id', '>', $avisosAntes)->latest('id')->first();
    verificar('Avisó al docente',
        $aviso !== null && $aviso->destinos()->where('tipo', DestinoEvento::Alumno->value)->where('destino_id', $docente)->exists());

    // ── 3. Las validaciones al solicitar ────────────────────────────────────
    echo PHP_EOL.'3. Se rehúsa lo que no cumple'.PHP_EOL;

    verificar('Por un hijo que no es suyo → 404',
        fallo(fn () => $gestor->solicitar($t1, $a2, $ventana->id, $fecha, '09:20', PREF.'x')) === 404);
    verificar('Con un docente que no le da clase → 403',
        fallo(fn () => $gestor->solicitar($t1, $a1, $ventanaDx->id, $fecha, '09:20', PREF.'x')) === 403);
    verificar('Una hora que no es hueco (09:05) → 422',
        fallo(fn () => $gestor->solicitar($t1, $a1, $ventana->id, $fecha, '09:05', PREF.'x')) === 422);
    verificar('Una hora fuera de la ventana (08:00) → 422',
        fallo(fn () => $gestor->solicitar($t1, $a1, $ventana->id, $fecha, '08:00', PREF.'x')) === 422);
    verificar('Un día que no es el de la ventana → 422',
        fallo(fn () => $gestor->solicitar($t1, $a1, $ventana->id, $fechaObj->copy()->addDay()->format('Y-m-d'), '09:00', PREF.'x')) === 422);

    $pasado = Carbon::now()->subDays(4)->startOfDay();
    $ventanaPasada = DisponibilidadCitaDocente::create(['persona_id' => $docente, 'dia_semana' => $pasado->isoWeekday(), 'hora_inicio' => '09:00', 'hora_fin' => '11:00', 'modalidad' => 'presencial', 'duracion_min' => 20]);
    verificar('Una hora en el pasado → 422',
        fallo(fn () => $gestor->solicitar($t1, $a1, $ventanaPasada->id, $pasado->format('Y-m-d'), '09:00', PREF.'x')) === 422);

    // Duplicado de la MISMA familia en un hueco libre (09:40).
    $gestor->solicitar($t1, $a1, $ventana->id, $fecha, '09:40', PREF.'otra');
    verificar('Una solicitud idéntica repetida → 422',
        fallo(fn () => $gestor->solicitar($t1, $a1, $ventana->id, $fecha, '09:40', PREF.'x')) === 422);

    // ── 4. Confirmar, con bloqueo y sin encimarse ───────────────────────────
    echo PHP_EOL.'4. Confirmar bajo bloqueo; no se enciman dos'.PHP_EOL;

    // La OTRA familia pide el mismo hueco (aún nadie lo confirmó → se permite).
    $c2 = $gestor->solicitar($t2, $a2, $ventana->id, $fecha, '09:00', PREF.'a2');
    verificar('Dos familias distintas pueden solicitar el mismo hueco', $c2->estado === Cita::SOLICITADA);

    $avisosAntes2 = (int) (Aviso::query()->max('id') ?? 0);
    $gestor->confirmar($c1, $docente, PREF.'nos vemos');
    verificar('El docente confirmó', $c1->fresh()->estado === Cita::CONFIRMADA);
    $avisoConf = Aviso::query()->where('id', '>', $avisosAntes2)->latest('id')->first();
    verificar('Avisó al solicitante',
        $avisoConf !== null && $avisoConf->destinos()->where('tipo', DestinoEvento::Alumno->value)->where('destino_id', $t1)->exists());

    verificar('Confirmar el hueco ya ocupado → 422 (traslape)',
        fallo(fn () => $gestor->confirmar($c2, $docente, null)) === 422);
    verificar('Confirmar por un docente que no es el suyo → 404',
        fallo(fn () => $gestor->confirmar($c1, $dxPersona, null)) === 404);
    verificar('Confirmar una cita ya confirmada → 422',
        fallo(fn () => $gestor->confirmar($c1, $docente, null)) === 422);

    // Con c1 CONFIRMADA, la otra familia ya no puede ni pedir ese hueco.
    $gestor->cancelar($c2, $t2, PREF.'ya no');
    verificar('Pedir un hueco YA confirmado → 422',
        fallo(fn () => $gestor->solicitar($t2, $a2, $ventana->id, $fecha, '09:00', PREF.'x')) === 422);

    // ── 5. Rechazar, cancelar, marcar ───────────────────────────────────────
    echo PHP_EOL.'5. Rechazar / cancelar / marcar'.PHP_EOL;

    $c3 = $gestor->solicitar($t1, $a1, $ventana->id, $fecha, '10:00', PREF.'tercera');
    verificar('Rechazar sin motivo → 422',
        fallo(fn () => $gestor->rechazar($c3, $docente, '  ')) === 422);
    $gestor->rechazar($c3, $docente, PREF.'no puedo ese día');
    verificar('Rechazada, con la respuesta guardada', $c3->fresh()->estado === Cita::RECHAZADA && str_contains((string) $c3->fresh()->respuesta, 'no puedo'));

    verificar('Cancelar por quien no es parte → 403',
        fallo(fn () => $gestor->cancelar($c1, $dxPersona, PREF.'x')) === 403);
    verificar('Marcar una cita FUTURA → 422',
        fallo(fn () => $gestor->marcar($c1, $docente, Cita::REALIZADA)) === 422);

    // Una cita confirmada YA pasada: se marca, no se cancela.
    $pasada = Cita::create([
        'docente_persona_id' => $docente, 'alumno_persona_id' => $a1, 'solicitante_persona_id' => $t1,
        'inicio' => Carbon::now()->subDay(), 'fin' => Carbon::now()->subDay()->addMinutes(20),
        'modalidad' => 'presencial', 'motivo' => PREF.'vieja', 'estado' => Cita::CONFIRMADA,
    ]);
    verificar('Cancelar una cita ya pasada → 422',
        fallo(fn () => $gestor->cancelar($pasada, $t1, PREF.'x')) === 422);
    $gestor->marcar($pasada, $docente, Cita::REALIZADA);
    verificar('Marcada como realizada', $pasada->fresh()->estado === Cita::REALIZADA);
    verificar('Marcar con un desenlace inválido → 422',
        fallo(fn () => $gestor->marcar($pasada, $docente, 'solicitada')) === 422);

    // La familia cancela su cita confirmada futura.
    $gestor->cancelar($c1, $t1, PREF.'surgió algo');
    verificar('La familia canceló su cita', $c1->fresh()->estado === Cita::CANCELADA);

    // ── 6. Los controladores y el cableado de permisos ──────────────────────
    echo PHP_EOL.'6. Controladores y permisos'.PHP_EOL;

    $uDoc = Usuario::query()->where('persona_id', $docente)->first()
        ?? Usuario::create(['persona_id' => $docente, 'usuario' => PREF.random_int(100000, 999999), 'password' => Hash::make('x')]);
    $uT1 = Usuario::query()->where('persona_id', $t1)->first()
        ?? Usuario::create(['persona_id' => $t1, 'usuario' => PREF.random_int(100000, 999999), 'password' => Hash::make('x')]);

    $inertia = function ($usuario) {
        $p = Request::create('/', 'GET');
        $p->setUserResolver(fn () => $usuario);
        $p->headers->set('X-Inertia', 'true');
        $p->headers->set('X-Inertia-Version', '');

        return $p;
    };

    $ctrlDoc = app(DocenciaCitasController::class);
    $peticionDoc = $inertia($uDoc);
    $propsDoc = json_decode($ctrlDoc->index($peticionDoc)->toResponse($peticionDoc)->getContent(), true)['props'];
    verificar('El portal del docente trae su disponibilidad y sus citas',
        isset($propsDoc['disponibilidad']) && isset($propsDoc['citas']));

    $ctrlFam = app(CitaFamiliaController::class);
    $peticionFam = $inertia($uT1);
    $propsFam = json_decode($ctrlFam->index($peticionFam, Persona::find($a1))->toResponse($peticionFam)->getContent(), true)['props'];
    verificar('El portal de la familia trae a los docentes del hijo', isset($propsFam['docentes']));
    verificar('Abrir las citas de un hijo ajeno → 404',
        fallo(fn () => $ctrlFam->index(peticionDe($uT1), Persona::find($a2))) === 404);

    verificar('gestionar-mis-citas es del docente', CatalogoPermisos::correspondeA('gestionar-mis-citas', 'docente'));
    verificar('solicitar-citas es del padre de familia', CatalogoPermisos::correspondeA('solicitar-citas', 'padre_familia'));
} catch (Throwable $e) {
    verificar('La suite corrió hasta el final sin morir', false,
        get_class($e).': '.$e->getMessage().' @ '.basename($e->getFile()).':'.$e->getLine());
} finally {
    $db->rollBack();
}

echo PHP_EOL.'Resultado: '.($verificaciones - $fallidas).' correctas, '.$fallidas.' fallidas'.PHP_EOL;

exit($fallidas === 0 ? 0 : 1);
