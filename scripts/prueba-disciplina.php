<?php

/**
 * Incidencias y sanciones de conducta. Con rollback.
 *
 * Se corre con `php scripts/prueba-disciplina.php` desde la raíz.
 *
 * ── Qué se vigila, y por qué se caería si se rompe ─────────────────────────
 *  1. `reportada_por` / `aplicada_por` salen de la SESIÓN, no de la petición:
 *     el navegador no puede atribuirle una incidencia a otro. Y al EDITAR no se
 *     reescribe: quien la reportó sigue siendo quien la vio.
 *  2. La vigencia la manda el TIPO: `desde`/`hasta` sólo se guardan si el tipo
 *     `tiene_vigencia`; con uno puntual se anulan aunque el formulario los traiga.
 *  3. Una sanción sólo cita incidencias DEL MISMO alumno: citar la de otro se
 *     descarta, no se guarda.
 *  4. El docente sólo levanta incidencias de SUS alumnos: la matrícula ajena da
 *     403, y no porque la pantalla no la ofrezca —la lista no es una defensa—
 *     sino porque el controlador la vuelve a comprobar contra la asignación.
 *  5. La conducta la ve el padre sólo con `ver-conducta-hijo` Y el módulo
 *     encendido. Sin permiso, o con el módulo apagado, no llega.
 *
 * Comprobada mutando cada una de esas cinco reglas: al quitarlas caen
 * exactamente las verificaciones que las vigilan.
 */

use App\Http\Controllers\Disciplina\DocenteIncidenciaController;
use App\Http\Controllers\Disciplina\IncidenciaController;
use App\Http\Controllers\Disciplina\SancionController;
use App\Http\Controllers\PadreController;
use App\Models\Disciplina\Incidencia;
use App\Models\Disciplina\Sancion;
use App\Models\Disciplina\TipoIncidencia;
use App\Models\Disciplina\TipoSancion;
use App\Models\Identidad\Parentesco;
use App\Models\Identidad\Persona;
use App\Models\Identidad\Rol;
use App\Models\Identidad\TutorAlumno;
use App\Models\Identidad\Usuario;
use App\Models\Tenant;
use App\Services\Plataforma\ModulosDeLaEscuela;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
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

/** Una petición con datos, para invocar `guardar` como lo haría la pantalla. */
function peticion(array $datos = [], string $metodo = 'POST', ?Usuario $como = null): Request
{
    $p = Request::create('/', $metodo, $datos);
    $p->headers->set('X-Inertia', 'true');

    // El DocenteIncidenciaController lee `$peticion->user()`, no `Auth::user()`;
    // sin el resolutor, ese controlador ve null y todo es «no es tu alumno».
    if ($como !== null) {
        $p->setUserResolver(fn () => $como);
    }

    return $p;
}

/** Un usuario propio con un rol activo: nunca se toca el de nadie más. */
function usuarioConRol(string $rol): Usuario
{
    $persona = Persona::create([
        'nombre' => 'Prueba',
        'primer_apellido' => 'Disciplina',
        'segundo_apellido' => (string) random_int(1000, 9999),
        'sexo_id' => 1,
    ]);

    $cuenta = Usuario::create([
        'persona_id' => $persona->id,
        'usuario' => 'prueba_disc_'.random_int(100000, 999999),
        'email' => 'prueba_disc_'.random_int(100000, 999999).'@ejemplo.mx',
        'password' => Hash::make('secreto12345'),
        'rol_activo_id' => Rol::where('name', $rol)->firstOrFail()->id,
    ]);

    $cuenta->persona->asignacionesRol()->create([
        'rol_id' => $cuenta->rol_activo_id,
        'activo' => true,
    ]);

    return $cuenta->fresh(['persona', 'rolActivo']);
}

$db->beginTransaction();

try {
    // ── Datos reales: un docente con alumnos, una matrícula suya y una ajena ──
    $asignacion = $db->table('docente_asignatura_grupo as dag')
        ->join('inscripcion as i', 'i.asignatura_grupo_id', '=', 'dag.asignatura_grupo_id')
        ->join('usuarios as u', 'u.persona_id', '=', 'dag.persona_id')
        ->whereNull('i.deleted_at')
        ->whereNull('dag.deleted_at')
        ->select('dag.persona_id', 'u.id as usuario_id')
        ->first();

    if ($asignacion === null) {
        echo 'Esta escuela no tiene un docente con alumnos y cuenta; nada que probar.'.PHP_EOL;
        $db->rollBack();
        exit(0);
    }

    $docentePersonaId = (int) $asignacion->persona_id;

    // Sus matrículas (todas las de sus grupos) y una que NO es de él.
    $suyas = $db->table('inscripcion as i')
        ->join('docente_asignatura_grupo as dag', 'dag.asignatura_grupo_id', '=', 'i.asignatura_grupo_id')
        ->where('dag.persona_id', $docentePersonaId)
        ->whereNull('dag.deleted_at')
        ->whereNull('i.deleted_at')
        ->pluck('i.matricula_oferta_id')
        ->unique();

    $matriculaSuya = (int) $suyas->first();
    $matriculaAjena = (int) $db->table('matricula_oferta')
        ->whereNull('deleted_at')
        ->whereNotIn('id', $suyas)
        ->value('id');

    // El docente: se reutiliza su cuenta real y se le pone el rol activo docente
    // dentro de la transacción (rollback lo deshace). No se crea otra: la unique
    // de `usuarios.persona_id` no admite dos cuentas para la misma persona.
    $rolDocente = Rol::where('name', 'docente')->firstOrFail();
    $usuarioDocente = Usuario::findOrFail($asignacion->usuario_id);
    $usuarioDocente->forceFill(['rol_activo_id' => $rolDocente->id])->save();
    $usuarioDocente->persona->asignacionesRol()->firstOrCreate(
        ['rol_id' => $rolDocente->id],
        ['activo' => true],
    );
    $usuarioDocente = $usuarioDocente->fresh(['persona', 'rolActivo']);

    $admin = usuarioConRol('director_general');
    $otroAdmin = usuarioConRol('director_general');

    verificar('Hay un docente con alumnos y una matrícula ajena para probar',
        $matriculaSuya > 0 && $matriculaAjena > 0,
        "docente {$docentePersonaId}, suya {$matriculaSuya}, ajena {$matriculaAjena}");

    // ═══ 1. reportada_por sale de la sesión y no se reescribe al editar ═══
    echo PHP_EOL.'1. La reporta quien está en sesión, y editar no cambia eso'.PHP_EOL;

    $tipoInc = TipoIncidencia::query()->activos()->firstOrFail();
    $ctrlInc = app(IncidenciaController::class);

    auth()->login($admin);
    $ctrlInc->guardar(peticion([
        'matricula_oferta_id' => $matriculaSuya,
        'tipo_incidencia_id' => $tipoInc->id,
        'fecha' => '2026-08-24',
        'descripcion' => 'Incidencia de prueba (admin).',
    ]));

    $inc = Incidencia::query()->where('matricula_oferta_id', $matriculaSuya)
        ->where('descripcion', 'Incidencia de prueba (admin).')->latest('id')->first();

    verificar('Al crearla, reportada_por es la persona del admin en sesión',
        $inc !== null && $inc->reportada_por === $admin->persona_id);

    // Otro admin la edita: reportada_por NO cambia.
    auth()->login($otroAdmin);
    $ctrlInc->guardar(peticion([
        'matricula_oferta_id' => $matriculaSuya,
        'tipo_incidencia_id' => $tipoInc->id,
        'fecha' => '2026-08-24',
        'descripcion' => 'Editada por otro admin.',
    ], 'PUT'), $inc->fresh());

    $inc = $inc->fresh();
    verificar('Al editarla, reportada_por sigue siendo el primero',
        $inc->descripcion === 'Editada por otro admin.' && $inc->reportada_por === $admin->persona_id,
        "reportada_por={$inc->reportada_por}, admin={$admin->persona_id}");

    // ═══ 2. Sanción: aplicada_por, vigencia según el tipo, citar del mismo ═══
    echo PHP_EOL.'2. Sanción: la aplica quien está en sesión, la vigencia la manda el tipo'.PHP_EOL;

    $ctrlSan = app(SancionController::class);
    auth()->login($admin);

    // Un tipo PUNTUAL: aunque se manden fechas, se anulan.
    $tipoPuntual = TipoSancion::query()->where('tiene_vigencia', false)->firstOrFail();
    $ctrlSan->guardar(peticion([
        'matricula_oferta_id' => $matriculaSuya,
        'tipo_sancion_id' => $tipoPuntual->id,
        'fecha' => '2026-08-24',
        'desde' => '2026-08-25',
        'hasta' => '2026-08-27',
        'motivo' => 'Amonestación de prueba.',
    ]));

    $sanPuntual = Sancion::query()->where('motivo', 'Amonestación de prueba.')->latest('id')->first();
    verificar('La aplica la persona del admin en sesión',
        $sanPuntual !== null && $sanPuntual->aplicada_por === $admin->persona_id);
    verificar('Tipo puntual: desde/hasta quedan en NULL aunque se enviaron',
        $sanPuntual->desde === null && $sanPuntual->hasta === null);

    // Un tipo CON vigencia: las fechas sí se guardan.
    $tipoVigencia = TipoSancion::query()->where('tiene_vigencia', true)->firstOrFail();
    $ctrlSan->guardar(peticion([
        'matricula_oferta_id' => $matriculaSuya,
        'tipo_sancion_id' => $tipoVigencia->id,
        'fecha' => '2026-08-24',
        'desde' => '2026-08-25',
        'hasta' => '2026-08-27',
        'motivo' => 'Suspensión de prueba.',
    ]));

    $sanVigente = Sancion::query()->where('motivo', 'Suspensión de prueba.')->latest('id')->first();
    verificar('Tipo con vigencia: desde/hasta sí se guardan',
        $sanVigente !== null
        && $sanVigente->desde?->format('Y-m-d') === '2026-08-25'
        && $sanVigente->hasta?->format('Y-m-d') === '2026-08-27');
    verificar('vigente() es falso: la suspensión empieza mañana',
        $sanVigente->vigente() === false);

    // ── Citar incidencias: sólo las del MISMO alumno ──
    echo PHP_EOL.'3. Una sanción cita sólo incidencias del propio alumno'.PHP_EOL;

    $incSuya = Incidencia::create([
        'matricula_oferta_id' => $matriculaSuya,
        'tipo_incidencia_id' => $tipoInc->id,
        'fecha' => '2026-08-20',
        'descripcion' => 'La que origina la sanción.',
        'reportada_por' => $admin->persona_id,
    ]);
    $incAjena = Incidencia::create([
        'matricula_oferta_id' => $matriculaAjena,
        'tipo_incidencia_id' => $tipoInc->id,
        'fecha' => '2026-08-20',
        'descripcion' => 'De otro alumno, no debe colarse.',
        'reportada_por' => $admin->persona_id,
    ]);

    $ctrlSan->guardar(peticion([
        'matricula_oferta_id' => $matriculaSuya,
        'tipo_sancion_id' => $tipoPuntual->id,
        'fecha' => '2026-08-24',
        'motivo' => 'Sanción que cita incidencias.',
        'incidencias' => [$incSuya->id, $incAjena->id],
    ]));

    $sanCita = Sancion::query()->where('motivo', 'Sanción que cita incidencias.')->latest('id')->first();
    $citadas = $sanCita->incidencias()->pluck('incidencias.id');
    verificar('Cita la incidencia del propio alumno',
        $citadas->contains($incSuya->id));
    verificar('NO cita la incidencia de otro alumno',
        ! $citadas->contains($incAjena->id),
        'citadas: '.$citadas->implode(','));

    // El endpoint que ofrece las incidencias sólo trae las del alumno pedido.
    $ofrecidas = collect(json_decode($ctrlSan->incidenciasDe($matriculaSuya)->getContent(), true))
        ->pluck('id');
    verificar('incidenciasDe() sólo devuelve las del alumno consultado',
        $ofrecidas->contains($incSuya->id) && ! $ofrecidas->contains($incAjena->id));

    // ═══ 3. El docente sólo puede sobre SUS alumnos ═══
    echo PHP_EOL.'4. El docente levanta sólo de sus alumnos; la ajena da 403'.PHP_EOL;

    $ctrlDoc = app(DocenteIncidenciaController::class);
    auth()->login($usuarioDocente);

    $reqDoc = peticion([], 'GET', $usuarioDocente);
    $props = json_decode(
        $ctrlDoc->index($reqDoc)->toResponse($reqDoc)->getContent(),
        true
    )['props'];
    $idsAlumnos = collect($props['alumnos'])->pluck('matricula_oferta_id');
    verificar('Su lista de alumnos incluye la matrícula suya',
        $idsAlumnos->contains($matriculaSuya));
    verificar('Su lista de alumnos NO incluye la ajena',
        ! $idsAlumnos->contains($matriculaAjena));

    // Levanta una de su alumno: OK, y reportada_por es él.
    $ctrlDoc->guardar(peticion([
        'matricula_oferta_id' => $matriculaSuya,
        'tipo_incidencia_id' => $tipoInc->id,
        'fecha' => '2026-08-24',
        'descripcion' => 'Levantada por el docente.',
    ], 'POST', $usuarioDocente));
    $incDoc = Incidencia::query()->where('descripcion', 'Levantada por el docente.')->latest('id')->first();
    verificar('La que levanta el docente queda a su nombre',
        $incDoc !== null && $incDoc->reportada_por === $usuarioDocente->persona_id);

    // Intenta sobre una matrícula que no es de sus grupos: 403.
    $estado = null;
    try {
        $ctrlDoc->guardar(peticion([
            'matricula_oferta_id' => $matriculaAjena,
            'tipo_incidencia_id' => $tipoInc->id,
            'fecha' => '2026-08-24',
            'descripcion' => 'No debería poder.',
        ], 'POST', $usuarioDocente));
    } catch (HttpException $e) {
        $estado = $e->getStatusCode();
    }
    verificar('Levantar sobre una matrícula ajena da 403', $estado === 403,
        'estado='.($estado ?? 'sin excepción'));
    verificar('Y no la guardó',
        Incidencia::query()->where('descripcion', 'No debería poder.')->doesntExist());

    // ═══ 4. El padre ve la conducta sólo con permiso y módulo encendido ═══
    echo PHP_EOL.'5. La conducta la ve el padre con permiso y módulo encendido'.PHP_EOL;

    $alumnoPersonaId = (int) $db->table('matricula_oferta')->where('id', $matriculaSuya)->value('persona_id');
    $alumno = Persona::findOrFail($alumnoPersonaId);
    $parentescoId = Parentesco::query()->value('id');

    // Tutor CON permiso (padre_familia).
    $tutorConPermiso = usuarioConRol('padre_familia');
    TutorAlumno::create([
        'tutor_persona_id' => $tutorConPermiso->persona_id,
        'alumno_persona_id' => $alumnoPersonaId,
        'parentesco_id' => $parentescoId,
    ]);

    $ctrlPadre = app(PadreController::class);
    auth()->login($tutorConPermiso);

    $req = peticion([], 'GET');
    $req->setUserResolver(fn () => $tutorConPermiso);
    $props = json_decode($ctrlPadre->hijo($req, $alumno)->toResponse($req)->getContent(), true)['props'];

    verificar('El padre con permiso recibe la sección de conducta',
        $props['conducta'] !== null);
    verificar('La conducta trae incidencias y sanciones del hijo',
        $props['conducta'] !== null
        && count($props['conducta']['incidencias']) > 0
        && count($props['conducta']['sanciones']) > 0);

    // Tutor SIN el permiso (un rol que no lo tiene): no recibe conducta.
    $tutorSinPermiso = usuarioConRol('director_general'); // faceta administrativa: no lleva ver-conducta-hijo
    TutorAlumno::create([
        'tutor_persona_id' => $tutorSinPermiso->persona_id,
        'alumno_persona_id' => $alumnoPersonaId,
        'parentesco_id' => $parentescoId,
    ]);
    verificar('El rol sin ver-conducta-hijo no tiene el permiso',
        ! $tutorSinPermiso->can('ver-conducta-hijo'));

    auth()->login($tutorSinPermiso);
    $req2 = peticion([], 'GET');
    $req2->setUserResolver(fn () => $tutorSinPermiso);
    $props2 = json_decode($ctrlPadre->hijo($req2, $alumno)->toResponse($req2)->getContent(), true)['props'];
    verificar('Sin el permiso, la conducta llega nula',
        $props2['conducta'] === null);

    // Con permiso pero módulo APAGADO: tampoco llega.
    app(ModulosDeLaEscuela::class)->cambiar('disciplina', false);
    auth()->login($tutorConPermiso);
    $req3 = peticion([], 'GET');
    $req3->setUserResolver(fn () => $tutorConPermiso);
    $props3 = json_decode($ctrlPadre->hijo($req3, $alumno)->toResponse($req3)->getContent(), true)['props'];
    verificar('Con el módulo apagado, la conducta llega nula aunque haya permiso',
        $props3['conducta'] === null);
    app(ModulosDeLaEscuela::class)->cambiar('disciplina', true);

    // ═══ 6. Catálogos de conducta: alta, y no se apaga/borra lo que se usa ═══
    echo PHP_EOL.'6. Catálogos de conducta: alta con orden, y lo usado no se apaga ni se borra'.PHP_EOL;

    $ctrlCat = app(\App\Http\Controllers\Disciplina\CatalogoConductaController::class);
    auth()->login($admin);

    // Alta de un tipo de incidencia: nace encendido y con el siguiente orden.
    $ctrlCat->store(peticion([
        'clave' => 'prueba_tipo_'.random_int(1000, 9999),
        'nombre' => 'Tipo de prueba',
        'descripcion' => 'creado por la suite',
        'nivel' => 2,
    ]), 'incidencia');
    $nuevo = TipoIncidencia::query()->where('nombre', 'Tipo de prueba')->latest('id')->first();
    verificar('El alta crea el tipo encendido, con nivel y orden',
        $nuevo !== null && $nuevo->activo === true && $nuevo->nivel === 2 && $nuevo->orden > 0);

    // Un tipo de sanción con vigencia se guarda con la bandera puesta.
    $ctrlCat->store(peticion([
        'clave' => 'prueba_san_'.random_int(1000, 9999),
        'nombre' => 'Sanción de prueba',
        'tiene_vigencia' => true,
    ]), 'sancion');
    $nuevoSan = TipoSancion::query()->where('nombre', 'Sanción de prueba')->latest('id')->first();
    verificar('El tipo de sanción guarda tiene_vigencia', $nuevoSan?->tiene_vigencia === true);

    // Uno SIN uso se puede apagar.
    $ctrlCat->alternar(peticion(['activo' => false]), 'incidencia', $nuevo->id);
    verificar('Un tipo sin uso se puede apagar', $nuevo->fresh()->activo === false);

    // Uno EN USO no se puede apagar: hay una incidencia que lo referencia.
    Incidencia::create([
        'matricula_oferta_id' => $matriculaSuya,
        'tipo_incidencia_id' => $nuevo->id,
        'fecha' => '2026-08-24',
        'descripcion' => 'Usa el tipo de prueba.',
        'reportada_por' => $admin->persona_id,
    ]);
    $bloqueado = false;
    try {
        $ctrlCat->alternar(peticion(['activo' => false]), 'incidencia', $nuevo->id);
    } catch (\Illuminate\Validation\ValidationException $e) {
        $bloqueado = true;
    }
    verificar('Un tipo en uso no se puede apagar', $bloqueado);

    // Y en uso tampoco se borra: destroy responde con error y el tipo sigue vivo.
    $ctrlCat->destroy('incidencia', $nuevo->id);
    verificar('Un tipo en uso no se elimina', TipoIncidencia::query()->whereKey($nuevo->id)->exists());

    echo PHP_EOL."Resultado: ".($verificaciones - $fallidas)." correctas, {$fallidas} fallidas".PHP_EOL;
} finally {
    $db->rollBack();
}
