<?php

/**
 * El ACCESO del supervisor externo a su portal (módulo 4.1). Con rollback.
 *
 * Se corre con `php scripts/prueba-supervisor-externo.php` desde la raíz.
 *
 * ── Qué se vigila, y por qué ──────────────────────────────────────────────
 *  1. **Invitar aprovisiona una cuenta ACOTADA.** Reusa el aprovisionador de
 *     siempre, crea la persona mínima si no había, y deja vigencia.
 *  2. **El supervisor SÓLO ve lo suyo.** Su `persona_rol` no tiene campus, así
 *     que `campusVisibles()` le daría NULL = «todos»: la faceta se resuelve
 *     ANTES del campus y lo acota a sus expedientes asignados con acceso
 *     vigente. Ésta es la comprobación de seguridad central.
 *  3. **Un administrativo con el mismo permiso NO se acota como supervisor.**
 *     Lo que decide el alcance es la FACETA, no el permiso.
 *  4. **Puede aprobar horas y revisar informes de SU alumno, y de nadie más.**
 *     Las acciones rebotan con 403 sobre el practicante de otro supervisor.
 *  5. **No ve cartera ni calificaciones.** Ni el permiso las concede, ni la
 *     pantalla las manda.
 *  6. **Revocar corta el acceso.** El alcance queda en nada y, si no le queda
 *     ningún contacto vigente, se apaga su faceta.
 *  7. **La vigencia, en SQL y en PHP, concuerda.** La misma regla escrita dos
 *     veces se cruza: si se separa, el portal mostraría lo que no debe.
 */

use App\Exceptions\AvisoParaElUsuario;
use App\Http\Controllers\ProcesosFormativos\SupervisionController;
use App\Http\Controllers\ProcesosFormativos\SupervisorExternoController;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\Identidad\Persona;
use App\Models\Identidad\PersonaRol;
use App\Models\Identidad\Rol;
use App\Models\Identidad\Usuario;
use App\Models\ProcesosFormativos\BitacoraHoras;
use App\Models\ProcesosFormativos\EstadoExpediente;
use App\Models\ProcesosFormativos\InformeProceso;
use App\Models\ProcesosFormativos\OrganizacionContacto;
use App\Models\ProcesosFormativos\OrganizacionReceptora;
use App\Models\ProcesosFormativos\ReglaProceso;
use App\Models\ProcesosFormativos\SituacionOrganizacion;
use App\Models\ProcesosFormativos\TipoProcesoFormativo;
use App\Models\Tenant;
use App\Services\ProcesosFormativos\AccesoSupervisorExterno;
use App\Services\ProcesosFormativos\AlcanceDeExpedientes;
use App\Services\ProcesosFormativos\AsignadorDePlaza;
use App\Services\ProcesosFormativos\InformesYEvaluaciones;
use App\Services\ProcesosFormativos\RegistradorDeHoras;
use App\Services\ProcesosFormativos\TransicionDeExpediente;
use App\Support\CatalogoPermisos;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

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

function rehusaCon(int $codigo, callable $acto, ?string $contiene = null): bool
{
    try {
        $acto();

        return false;
    } catch (AvisoParaElUsuario $e) {
        return $e->getStatusCode() === $codigo
            && ($contiene === null || str_contains($e->getMessage(), $contiene));
    }
}

/**
 * Lee las props de Inertia que devuelve un método de controlador, como lo vería
 * `$como`. Se autentica a `$como` porque Laravel, al reponer el `request` en el
 * contenedor, PISA el userResolver por el del guard: sin el login, el
 * controlador vería al usuario de la sesión, no al que queremos probar.
 */
function props(object $controlador, string $metodo, Usuario $como, array $extra = []): array
{
    global $global;

    $peticion = Illuminate\Http\Request::create('/', 'GET');
    $peticion->headers->set('X-Inertia', 'true');
    $peticion->headers->set('X-Inertia-Version', '');
    app()->instance('request', $peticion);
    auth()->login($como);
    $peticion->setUserResolver(fn () => $como);

    try {
        $respuesta = $controlador->{$metodo}($peticion, ...$extra);

        return json_decode($respuesta->toResponse($peticion)->getContent(), true)['props'];
    } finally {
        auth()->login($global);
    }
}

function usuarioConRol(string $rol, ?int $personaId = null): Usuario
{
    $personaId ??= Persona::create([
        'nombre' => 'Prueba',
        'primer_apellido' => 'Supervisor',
        'segundo_apellido' => (string) random_int(1000, 9999),
    ])->id;

    $rolId = Rol::query()->where('name', $rol)->firstOrFail()->id;

    $cuenta = Usuario::query()->where('persona_id', $personaId)->first()
        ?? Usuario::create([
            'persona_id' => $personaId,
            'usuario' => 'zzsup_'.random_int(100000, 999999),
            'email' => 'zzsup_'.random_int(100000, 999999).'@ejemplo.mx',
            'password' => Hash::make('secreto12345'),
            'rol_activo_id' => $rolId,
        ]);

    $cuenta->forceFill(['rol_activo_id' => $rolId])->save();

    PersonaRol::query()->firstOrCreate(
        ['persona_id' => $personaId, 'rol_id' => $rolId],
        ['activo' => true, 'campus_id' => null],
    );

    return $cuenta->fresh(['persona', 'rolActivo']);
}

const PREFIJO = 'ZZSUP-';

$db->beginTransaction();

try {
    $acceso = app(AccesoSupervisorExterno::class);
    $alcance = app(AlcanceDeExpedientes::class);
    $horas = app(RegistradorDeHoras::class);
    $papeleo = app(InformesYEvaluaciones::class);
    $transiciones = app(TransicionDeExpediente::class);
    $asignador = app(AsignadorDePlaza::class);
    $portal = app(SupervisionController::class);
    $adminCtrl = app(SupervisorExternoController::class);

    $global = usuarioConRol('director_general');
    auth()->login($global);

    // ── El catálogo de roles trae la faceta ────────────────────────────────
    echo PHP_EOL.'0. La faceta supervisor_externo existe con sus permisos'.PHP_EOL;

    $facetaSup = Rol::query()->where('name', CatalogoPermisos::SUPERVISOR)->first();
    verificar('La faceta supervisor_externo está sembrada', $facetaSup !== null);
    verificar('Es faceta (sin padre) y protegida',
        $facetaSup?->rol_padre_id === null && (bool) $facetaSup?->protegido === true);
    verificar('Concede aprobar-horas, revisar-informes y ver-mis-supervisados',
        $facetaSup?->concede('aprobar-horas-formativas')
        && $facetaSup?->concede('revisar-informes-formativos')
        && $facetaSup?->concede('ver-mis-supervisados'));
    verificar('aprobar-horas-formativas pertenece a las dos facetas',
        CatalogoPermisos::correspondeA('aprobar-horas-formativas', CatalogoPermisos::ADMINISTRATIVO)
        && CatalogoPermisos::correspondeA('aprobar-horas-formativas', CatalogoPermisos::SUPERVISOR));

    // ── Escenario: dos organizaciones, dos supervisores, dos expedientes ────
    $tipo = TipoProcesoFormativo::query()->where('clave', 'servicio_social')->firstOrFail();
    $recibe = SituacionOrganizacion::query()->where('acepta_asignaciones', true)->firstOrFail();

    $matriculas = MatriculaOferta::query()->whereHas('oferta.plan')->with('oferta')->take(2)->get();
    verificar('Hay dos matrículas con las que construir el escenario', $matriculas->count() === 2);

    $regla = ReglaProceso::create([
        'nombre' => PREFIJO.'Regla',
        'tipo_proceso_id' => $tipo->id,
        'plan_id' => $matriculas[0]->oferta->plan_id,
    ]);
    $version = $regla->versiones()->create([
        'version' => 1,
        'vigente_desde' => now()->subYear()->toDateString(),
        'obligatorio' => true,
        'horas_requeridas' => 480,
        'tolerancia_horas' => 0,
        'max_horas_dia' => 8,
        'max_horas_semana' => 40,
        'informes_parciales' => 1,
        'periodicidad_informe_dias' => 30,
        'exige_informe_final' => true,
        'exige_evaluacion_supervisor' => true,
    ]);

    $orgA = OrganizacionReceptora::create(['razon_social' => PREFIJO.'Org A', 'situacion_id' => $recibe->id]);
    $orgB = OrganizacionReceptora::create(['razon_social' => PREFIJO.'Org B', 'situacion_id' => $recibe->id]);

    $contactoA = OrganizacionContacto::create([
        'organizacion_id' => $orgA->id, 'nombre' => 'Laura Domínguez Ruiz',
        'cargo' => 'Jefa de área', 'correo' => 'zzsupA_'.random_int(1000, 9999).'@ejemplo.mx',
        'es_supervisor' => true,
    ]);
    $contactoB = OrganizacionContacto::create([
        'organizacion_id' => $orgB->id, 'nombre' => 'Pedro Salas Vega',
        'cargo' => 'Coordinador', 'correo' => 'zzsupB_'.random_int(1000, 9999).'@ejemplo.mx',
        'es_supervisor' => true,
    ]);

    $inicio = now()->subDays(20)->startOfWeek(Carbon\CarbonInterface::MONDAY);

    $armar = function (MatriculaOferta $matricula, int $orgId, int $contactoId) use ($transiciones, $asignador, $version, $tipo, $global, $inicio) {
        $exp = $transiciones->abrir([
            'matricula_oferta_id' => $matricula->id,
            'tipo_proceso_id' => $tipo->id,
            'regla_version_id' => $version->id,
            'horas_requeridas' => 480,
        ], $global);

        foreach ([EstadoExpediente::Solicitado, EstadoExpediente::EnRevision, EstadoExpediente::Aprobado] as $paso) {
            $exp = $transiciones->mover($exp, $paso, $global);
        }

        $exp = $asignador->asignar($exp, [
            'organizacion_id' => $orgId,
            'contacto_supervisor_id' => $contactoId,
            'fecha_inicio' => $inicio->toDateString(),
            'fecha_fin_programada' => $inicio->copy()->addMonths(6)->toDateString(),
        ], $global);

        return $transiciones->mover($exp, EstadoExpediente::EnCurso, $global);
    };

    $expA = $armar($matriculas[0], $orgA->id, $contactoA->id);
    $expB = $armar($matriculas[1], $orgB->id, $contactoB->id);

    verificar('Los dos expedientes quedaron en curso con su supervisor',
        (int) $expA->contacto_supervisor_id === $contactoA->id
        && (int) $expB->contacto_supervisor_id === $contactoB->id);

    // ── 1. Invitar aprovisiona ──────────────────────────────────────────────
    echo PHP_EOL.'1. Invitar aprovisiona una cuenta acotada'.PHP_EOL;

    verificar('Antes de invitar, el contacto está «sin_invitar»',
        $contactoA->estadoAcceso() === 'sin_invitar' && $contactoA->persona_id === null);

    $resA = $acceso->invitar($contactoA, [], $global);
    $contactoA->refresh();

    verificar('Devuelve una contraseña temporal (cuenta nueva)', is_string($resA['password']) && strlen($resA['password']) >= 8);
    verificar('El contacto quedó ligado a una persona', $contactoA->persona_id !== null);
    verificar('La persona lleva el nombre del contacto',
        $contactoA->persona?->nombreCompleto() === 'Laura Domínguez Ruiz');
    verificar('La cuenta existe y tiene acceso configurado',
        $resA['usuario']->acceso_configurado === true);
    verificar('Tiene la faceta supervisor activa',
        PersonaRol::query()->where('persona_id', $contactoA->persona_id)
            ->where('rol_id', $facetaSup->id)->where('activo', true)->exists());
    verificar('Quedó marcada la invitación y el acceso desde hoy',
        $contactoA->invitado_en !== null
        && $contactoA->acceso_desde?->toDateString() === now()->toDateString());
    verificar('estadoAcceso() ahora es «vigente»', $contactoA->estadoAcceso() === 'vigente');
    verificar('accesoVigente() es verdadero', $contactoA->accesoVigente() === true);

    $resB = $acceso->invitar($contactoB, [], $global);
    $contactoB->refresh();

    $supA = Usuario::query()->where('persona_id', $contactoA->persona_id)->first()->fresh(['rolActivo']);
    $supB = Usuario::query()->where('persona_id', $contactoB->persona_id)->first()->fresh(['rolActivo']);

    // ── 2. Invitar exige correo ─────────────────────────────────────────────
    echo PHP_EOL.'2. Invitar exige un correo con qué entrar'.PHP_EOL;

    $sinCorreo = OrganizacionContacto::create([
        'organizacion_id' => $orgA->id, 'nombre' => 'Sin Correo', 'es_supervisor' => true,
    ]);
    verificar('Un contacto sin correo se rehúsa con 422',
        rehusaCon(422, fn () => $acceso->invitar($sinCorreo, [], $global), 'correo'));

    // ── 3. El supervisor sólo ve lo suyo ────────────────────────────────────
    echo PHP_EOL.'3. El alcance: sólo sus expedientes, aunque no tenga campus'.PHP_EOL;

    verificar('Su persona_rol NO tiene campus (campusVisibles daría «todos»)',
        $supA->campusVisibles() === null);

    $mios = $alcance->acotar(\App\Models\ProcesosFormativos\ExpedienteProceso::query(), $supA)->pluck('id')->all();
    verificar('Ve EXACTAMENTE su expediente', $mios === [$expA->id], implode(',', $mios));
    verificar('NO ve el del otro supervisor', ! in_array($expB->id, $mios, true));

    // El administrativo con el MISMO permiso NO se acota como supervisor.
    $vistosGlobal = $alcance->acotar(\App\Models\ProcesosFormativos\ExpedienteProceso::query(), $global)->pluck('id')->all();
    verificar('El director (mismo permiso, otra faceta) ve los DOS',
        in_array($expA->id, $vistosGlobal, true) && in_array($expB->id, $vistosGlobal, true));
    verificar('El director tiene aprobar-horas pero NO es supervisor',
        $global->can('aprobar-horas-formativas') === true);

    // ── 4. Alcance por expediente ───────────────────────────────────────────
    echo PHP_EOL.'4. exigirQueAlcance: el suyo pasa, el ajeno 403'.PHP_EOL;

    $alcanceOk = true;
    try {
        $alcance->exigirQueAlcance($expA, $supA);
    } catch (AvisoParaElUsuario) {
        $alcanceOk = false;
    }
    verificar('Alcanza su propio expediente', $alcanceOk);
    verificar('El del otro supervisor responde 403',
        rehusaCon(403, fn () => $alcance->exigirQueAlcance($expB, $supA)));

    // ── 5. Aprobar horas: las suyas sí, las ajenas no ───────────────────────
    echo PHP_EOL.'5. Aprobar horas: de su alumno sí, de otro 403'.PHP_EOL;

    $jornadaA = $horas->capturar($expA, [
        'fecha' => $inicio->copy()->addDays(1)->toDateString(),
        'hora_inicio' => '09:00', 'hora_fin' => '13:00', 'minutos_descanso' => 0,
        'actividad' => 'Apoyo', 'modalidad_id' => null,
    ], $global);
    $jornadaB = $horas->capturar($expB, [
        'fecha' => $inicio->copy()->addDays(1)->toDateString(),
        'hora_inicio' => '09:00', 'hora_fin' => '13:00', 'minutos_descanso' => 0,
        'actividad' => 'Apoyo', 'modalidad_id' => null,
    ], $global);

    $horas->aprobar($jornadaA, $supA);
    verificar('El supervisor aprueba la jornada de SU alumno',
        $jornadaA->fresh()->estado === BitacoraHoras::APROBADA);
    verificar('NO puede aprobar la jornada del alumno de otro (403)',
        rehusaCon(403, fn () => $horas->aprobar($jornadaB, $supA)));
    verificar('La jornada ajena sigue capturada, intacta',
        $jornadaB->fresh()->estado === BitacoraHoras::CAPTURADA);

    // ── 6. Revisar informes: los suyos sí, los ajenos no ────────────────────
    echo PHP_EOL.'6. Revisar informes: de su alumno sí, de otro 403'.PHP_EOL;

    $informeA = $expA->informes()->first();
    $informeB = $expB->informes()->first();
    $informeA->forceFill(['estado' => InformeProceso::ENTREGADO, 'entregado_en' => now()])->save();
    $informeB->forceFill(['estado' => InformeProceso::ENTREGADO, 'entregado_en' => now()])->save();

    $papeleo->revisar($informeA, true, null, $supA);
    verificar('El supervisor acepta el informe de SU alumno',
        $informeA->fresh()->estado === InformeProceso::ACEPTADO);
    verificar('NO puede revisar el informe del alumno de otro (403)',
        rehusaCon(403, fn () => $papeleo->revisar($informeB, true, null, $supA)));

    // ── 7. No ve cartera ni calificaciones ──────────────────────────────────
    echo PHP_EOL.'7. El supervisor no alcanza cartera ni calificaciones'.PHP_EOL;

    foreach (['ver-adeudos', 'ver-alumnos', 'ver-historial-academico', 'ver-procesos-formativos', 'facturar'] as $prohibido) {
        verificar("No concede «{$prohibido}»", $supA->can($prohibido) === false);
    }
    verificar('Sí concede lo suyo (ver-mis-supervisados)', $supA->can('ver-mis-supervisados') === true);

    $detalle = props($portal, 'show', $supA, [$expA]);
    $exp = $detalle['expediente'];
    verificar('La pantalla trae horas, informes y evaluaciones',
        isset($exp['horas'], $exp['informes'], $exp['evaluaciones']));
    $sensibles = ['cartera', 'adeudos', 'saldo', 'calificaciones', 'promedio', 'documentos', 'liberacion', 'liberaciones', 'transiciones', 'excepciones', 'papeleo_pendiente'];
    $filtrados = array_values(array_filter($sensibles, fn ($k) => array_key_exists($k, $exp)));
    verificar('NO trae cartera, calificaciones ni papeles', $filtrados === [], implode(',', $filtrados));
    verificar('Y las props tampoco traen catálogos administrativos',
        ! array_key_exists('catalogos', $detalle));

    $lista = props($portal, 'index', $supA);
    $idsPortal = array_map(fn ($e) => $e['id'], $lista['expedientes']);
    verificar('El listado del portal muestra sólo su expediente', $idsPortal === [$expA->id], implode(',', $idsPortal));

    verificar('Pedir el detalle de un expediente ajeno responde 403',
        rehusaCon(403, fn () => props($portal, 'show', $supA, [$expB])));

    // ── 8. Revocar corta el acceso ──────────────────────────────────────────
    echo PHP_EOL.'8. Revocar corta el acceso'.PHP_EOL;

    $acceso->revocar($contactoA, $global);
    $contactoA->refresh();

    verificar('estadoAcceso() ahora es «revocado»', $contactoA->estadoAcceso() === 'revocado');
    verificar('accesoVigente() es falso', $contactoA->accesoVigente() === false);

    $supA = $supA->fresh(['rolActivo']);
    $trasRevocar = $alcance->acotar(\App\Models\ProcesosFormativos\ExpedienteProceso::query(), $supA)->pluck('id')->all();
    verificar('Ya no alcanza NINGÚN expediente', $trasRevocar === [], implode(',', $trasRevocar));
    // Fresco a propósito: en producción el expediente se carga por petición, así
    // que su supervisor refleja la revocación; en el script hay que releerlo.
    $expAFresco = \App\Models\ProcesosFormativos\ExpedienteProceso::find($expA->id);
    verificar('Su expediente de antes responde 403',
        rehusaCon(403, fn () => $alcance->exigirQueAlcance($expAFresco, $supA)));

    $jornadaA2 = $horas->capturar($expA, [
        'fecha' => $inicio->copy()->addDays(2)->toDateString(),
        'hora_inicio' => '09:00', 'hora_fin' => '12:00', 'minutos_descanso' => 0,
        'actividad' => 'Apoyo', 'modalidad_id' => null,
    ], $global);
    verificar('Revocado, ya no puede aprobar horas (403)',
        rehusaCon(403, fn () => $horas->aprobar($jornadaA2, $supA)));
    verificar('Su faceta quedó apagada (no le queda nada que supervisar)',
        ! PersonaRol::query()->where('persona_id', $contactoA->persona_id)
            ->where('rol_id', $facetaSup->id)->where('activo', true)->exists());

    // ── 9. Dos contactos, misma persona: revocar uno conserva el otro ───────
    echo PHP_EOL.'9. Una persona que supervisa en dos organizaciones'.PHP_EOL;

    $personaDual = Persona::create(['nombre' => 'Doble', 'primer_apellido' => 'Supervisión']);
    $contactoD1 = OrganizacionContacto::create([
        'organizacion_id' => $orgA->id, 'nombre' => 'Doble Supervisión',
        'correo' => 'zzdual1_'.random_int(1000, 9999).'@ejemplo.mx',
        'es_supervisor' => true, 'persona_id' => $personaDual->id,
    ]);
    $contactoD2 = OrganizacionContacto::create([
        'organizacion_id' => $orgB->id, 'nombre' => 'Doble Supervisión',
        'correo' => 'zzdual2_'.random_int(1000, 9999).'@ejemplo.mx',
        'es_supervisor' => true, 'persona_id' => $personaDual->id,
    ]);

    $acceso->invitar($contactoD1, [], $global);
    $acceso->invitar($contactoD2, [], $global);

    $acceso->revocar($contactoD1, $global);

    verificar('Al revocar uno, el otro contacto sigue vigente',
        $contactoD2->fresh()->accesoVigente() === true);
    verificar('La faceta NO se apaga: aún supervisa en la otra organización',
        PersonaRol::query()->where('persona_id', $personaDual->id)
            ->where('rol_id', $facetaSup->id)->where('activo', true)->exists());

    $acceso->revocar($contactoD2, $global);
    verificar('Al revocar el último, ya sí se apaga la faceta',
        ! PersonaRol::query()->where('persona_id', $personaDual->id)
            ->where('rol_id', $facetaSup->id)->where('activo', true)->exists());

    // ── 10. La vigencia, en SQL y en PHP, concuerda ─────────────────────────
    echo PHP_EOL.'10. La vigencia: el scope SQL y accesoVigente() coinciden'.PHP_EOL;

    $casos = [
        'sin_invitar' => ['invitado_en' => null, 'acceso_desde' => null, 'acceso_hasta' => null, 'acceso_revocado_en' => null],
        'vigente' => ['invitado_en' => now(), 'acceso_desde' => now()->subDay(), 'acceso_hasta' => null, 'acceso_revocado_en' => null],
        'vencido' => ['invitado_en' => now(), 'acceso_desde' => now()->subMonth(), 'acceso_hasta' => now()->subDay(), 'acceso_revocado_en' => null],
        'programado' => ['invitado_en' => now(), 'acceso_desde' => now()->addWeek(), 'acceso_hasta' => null, 'acceso_revocado_en' => null],
        'revocado' => ['invitado_en' => now(), 'acceso_desde' => now()->subDay(), 'acceso_hasta' => null, 'acceso_revocado_en' => now()],
    ];

    $ids = [];
    foreach ($casos as $estado => $cols) {
        $c = OrganizacionContacto::create(array_merge([
            'organizacion_id' => $orgA->id, 'nombre' => 'Caso '.$estado,
            'correo' => 'zzcaso_'.$estado.'@ejemplo.mx', 'es_supervisor' => true,
        ], $cols));
        $ids[$estado] = $c->id;
        verificar("estadoAcceso() = «{$estado}»", $c->estadoAcceso() === $estado, $c->estadoAcceso());
    }

    $vigentesSql = OrganizacionContacto::query()->whereIn('id', $ids)->conAccesoVigente()->pluck('id')->all();
    $vigentesPhp = collect($ids)->filter(
        fn ($id) => OrganizacionContacto::find($id)->accesoVigente()
    )->values()->all();
    sort($vigentesSql);
    sort($vigentesPhp);
    verificar('El scope SQL y accesoVigente() devuelven el MISMO conjunto',
        $vigentesSql === $vigentesPhp, 'sql='.implode(',', $vigentesSql).' php='.implode(',', $vigentesPhp));
    verificar('Sólo el «vigente» está adentro',
        $vigentesSql === [$ids['vigente']]);

    // ── 11. La pantalla del administrador ───────────────────────────────────
    echo PHP_EOL.'11. La pantalla del administrador lista y cuenta'.PHP_EOL;

    $adminProps = props($adminCtrl, 'index', $global);
    $porId = collect($adminProps['contactos'])->keyBy('id');
    verificar('Lista el contacto A con su expediente contado',
        (int) ($porId[$contactoA->id]['expedientes'] ?? -1) === 1);
    verificar('Y con su estado de acceso (revocado)',
        ($porId[$contactoA->id]['estado'] ?? null) === 'revocado');
    verificar('El contacto B aparece vigente',
        ($porId[$contactoB->id]['estado'] ?? null) === 'vigente');
} catch (Throwable $e) {
    verificar('La suite corrió hasta el final sin morir', false, get_class($e).': '.$e->getMessage()
        .' @ '.basename($e->getFile()).':'.$e->getLine());
} finally {
    $db->rollBack();
}

echo PHP_EOL.'Resultado: '.($verificaciones - $fallidas).' correctas, '.$fallidas.' fallidas'.PHP_EOL;

exit($fallidas === 0 ? 0 : 1);
