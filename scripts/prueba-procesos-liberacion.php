<?php

/**
 * La liberación y la interfaz con titulación (fase 6). Con rollback.
 *
 * Se corre con `php scripts/prueba-procesos-liberacion.php` desde la raíz.
 *
 * ── Qué se vigila, y por qué ──────────────────────────────────────────────
 *  1. **NUNCA se libera por horas.** Alcanzarlas quita UN impedimento; liberar
 *     es un acto con permiso, folio y snapshot. Automatizarlo emitiría
 *     constancias de gente que todavía debe su informe final.
 *  2. **Se rehúsa con la LISTA de lo que falta**, no con un «no se puede».
 *  3. **El FOLIO es atómico y único**: sale de un contador con
 *     `LAST_INSERT_ID`, nunca de un `MAX(folio)+1`.
 *  4. **El SNAPSHOT congela** lo que el documento dice: releerlo contra las
 *     tablas de hoy haría que el mismo folio ampare dos textos distintos.
 *  5. **Corregir NO edita**: emite otra y jubila la anterior. Las dos se
 *     conservan, porque el folio de la primera circula en un papel firmado.
 *  6. **Una liberación vigente por expediente**, sostenido por una columna
 *     generada — con un único pelado, la corrección sería imposible.
 *  7. **`RequisitoFormativo` contesta y NO cablea nada**: `ValidadorTitulo` y
 *     `EstadoCertificacion` siguen sin conocerlo.
 */

use App\Exceptions\AvisoParaElUsuario;
use App\Http\Controllers\ProcesosFormativos\ExpedienteFormativoController;
use App\Http\Controllers\ProcesosFormativos\LiberacionFormativaController;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\Identidad\Persona;
use App\Models\Identidad\Rol;
use App\Models\Identidad\Usuario;
use App\Models\ProcesosFormativos\BitacoraHoras;
use App\Models\ProcesosFormativos\EstadoExpediente;
use App\Models\ProcesosFormativos\EvaluacionProceso;
use App\Models\ProcesosFormativos\ExpedienteProceso;
use App\Models\ProcesosFormativos\LiberacionProceso;
use App\Models\ProcesosFormativos\OrganizacionReceptora;
use App\Models\ProcesosFormativos\ReglaProceso;
use App\Models\ProcesosFormativos\SituacionOrganizacion;
use App\Models\ProcesosFormativos\TipoProcesoFormativo;
use App\Models\Tenant;
use App\Services\ProcesosFormativos\AsignadorDePlaza;
use App\Services\ProcesosFormativos\ConstanciaFormativa;
use App\Services\ProcesosFormativos\InformesYEvaluaciones;
use App\Services\ProcesosFormativos\LiberadorDeExpediente;
use App\Services\ProcesosFormativos\RegistradorDeHoras;
use App\Services\ProcesosFormativos\RequisitoFormativo;
use App\Services\ProcesosFormativos\TransicionDeExpediente;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\PermissionRegistrar;

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

function usuarioConRol(string $rol, ?int $personaId = null): Usuario
{
    $personaId ??= Persona::create([
        'nombre' => 'Prueba',
        'primer_apellido' => 'Liberacion',
        'segundo_apellido' => (string) random_int(1000, 9999),
        'sexo_id' => 1,
    ])->id;

    $rolId = Rol::where('name', $rol)->firstOrFail()->id;

    $cuenta = Usuario::query()->where('persona_id', $personaId)->first();

    $cuenta
        ? $cuenta->forceFill(['rol_activo_id' => $rolId])->save()
        : $cuenta = Usuario::create([
            'persona_id' => $personaId,
            'usuario' => 'prueba_lib_'.random_int(100000, 999999),
            'email' => 'prueba_lib_'.random_int(100000, 999999).'@ejemplo.mx',
            'password' => Hash::make('secreto12345'),
            'rol_activo_id' => $rolId,
        ]);

    $cuenta->persona->asignacionesRol()->firstOrCreate(
        ['rol_id' => $rolId],
        ['activo' => true, 'campus_id' => null],
    );

    return $cuenta->fresh(['persona', 'rolActivo']);
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

const PREFIJO = 'ZZLIB-';

$db->beginTransaction();

try {
    $liberador = app(LiberadorDeExpediente::class);
    $requisito = app(RequisitoFormativo::class);
    $constancia = app(ConstanciaFormativa::class);
    $horas = app(RegistradorDeHoras::class);
    $papeleo = app(InformesYEvaluaciones::class);
    $transiciones = app(TransicionDeExpediente::class);
    $asignador = app(AsignadorDePlaza::class);
    $expedientes = app(ExpedienteFormativoController::class);

    $global = usuarioConRol('director_general');
    auth()->login($global);

    DB::table('liberaciones_proceso')->delete();
    DB::table('expedientes_proceso')->delete();
    DB::table('reglas_proceso')->delete();

    $tipo = TipoProcesoFormativo::query()->where('clave', 'servicio_social')->firstOrFail();

    $matricula = MatriculaOferta::query()->whereHas('oferta.plan')->with('oferta')->first();

    verificar('Hay una matrícula con la que construir el escenario', $matricula !== null);

    $regla = ReglaProceso::create([
        'nombre' => PREFIJO.'Regla',
        'tipo_proceso_id' => $tipo->id,
        'plan_id' => $matricula->oferta->plan_id,
    ]);

    $version = $regla->versiones()->create([
        'version' => 1,
        'vigente_desde' => now()->subYear()->toDateString(),
        'obligatorio' => true,
        'horas_requeridas' => 20,
        'tolerancia_horas' => 0,
        'exige_informe_final' => true,
        'exige_evaluacion_supervisor' => true,
        'cuenta_para_titulacion' => true,
    ]);

    $inicio = now()->startOfWeek(Carbon\CarbonInterface::MONDAY)->subWeeks(3);

    $expediente = $transiciones->abrir([
        'matricula_oferta_id' => $matricula->id,
        'tipo_proceso_id' => $tipo->id,
        'regla_version_id' => $version->id,
        'horas_requeridas' => 20,
    ], $global);

    $organizacion = OrganizacionReceptora::create([
        'razon_social' => PREFIJO.'Centro de asistencia',
        'nombre_comercial' => PREFIJO.'CAJ',
        'situacion_id' => SituacionOrganizacion::query()->where('acepta_asignaciones', true)->firstOrFail()->id,
    ]);

    foreach ([EstadoExpediente::Solicitado, EstadoExpediente::EnRevision, EstadoExpediente::Aprobado] as $paso) {
        $expediente = $transiciones->mover($expediente, $paso, $global);
    }

    $expediente = $asignador->asignar($expediente, [
        'organizacion_id' => $organizacion->id,
        'fecha_inicio' => $inicio->toDateString(),
        'fecha_fin_programada' => $inicio->copy()->addMonths(3)->toDateString(),
    ], $global);

    $expediente = $transiciones->mover($expediente, EstadoExpediente::EnCurso, $global);

    echo PHP_EOL.'1. Sólo se libera lo CONCLUIDO'.PHP_EOL;

    verificar('En curso NO se puede, y se dice el estado',
        collect($liberador->impedimentos($expediente))
            ->contains(fn ($m) => str_contains($m, 'En curso') && str_contains($m, 'sólo se libera lo que ya concluyó')),
        implode(' | ', $liberador->impedimentos($expediente)));

    verificar('Y emitir se rehúsa',
        rehusaCon(422, fn () => $liberador->liberar($expediente, $global), 'sólo se libera'));

    echo PHP_EOL.'2. Concluido, pero le falta TODO'.PHP_EOL;

    $expediente = $transiciones->mover($expediente, EstadoExpediente::Concluido, $global);

    $faltan = $liberador->impedimentos($expediente);

    verificar('Se nombran las HORAS que faltan, con sus números',
        collect($faltan)->contains(fn ($m) => str_contains($m, 'Le faltan 20 horas')),
        implode(' | ', $faltan));

    verificar('El informe final',
        collect($faltan)->contains(fn ($m) => str_contains($m, 'Informe final')),
        implode(' | ', $faltan));

    verificar('Y la evaluación del supervisor',
        collect($faltan)->contains(fn ($m) => str_contains($m, 'supervisor')),
        implode(' | ', $faltan));

    verificar('Los tres a la vez, no de uno en uno', count($faltan) >= 3, (string) count($faltan));

    /*
     * Y un DOCUMENTO del momento «liberación». El caso se construye: sin él,
     * quitar esa comprobación no cambiaba ningún resultado —la regla del
     * escenario no pedía papeles al final— y la regla quedaba sin probar.
     */
    $papel = App\Models\Admisiones\DocumentoRequerido::query()->firstOrFail();

    $version->documentos()->create([
        'documento_id' => $papel->id,
        'momento' => 'liberacion',
        'obligatorio' => true,
    ]);
    $version->refresh()->load('documentos.documento');
    $expediente->refresh()->load('reglaVersion.documentos.documento');

    verificar('El documento que se pide PARA LIBERAR también se reclama, por su nombre',
        collect($liberador->impedimentos($expediente))
            ->contains(fn ($m) => str_contains($m, 'para liberar') && str_contains($m, $papel->nombre)),
        implode(' | ', $liberador->impedimentos($expediente)));

    echo PHP_EOL.'3. NUNCA se libera por horas'.PHP_EOL;

    // Las horas, completas: el proceso pide 20 y se le aprueban 24.
    $expediente->forceFill(['estado' => EstadoExpediente::EnCurso->value])->save();

    foreach ([0, 1, 2] as $dia) {
        $jornada = $horas->capturar($expediente->refresh(), [
            'fecha' => $inicio->copy()->addDays($dia)->toDateString(),
            'hora_inicio' => '09:00',
            'hora_fin' => '17:00',
            'actividad' => 'Jornada completa del día '.$dia.'.',
        ], $global);

        $horas->aprobar($jornada, $global);
    }

    $expediente->forceFill(['estado' => EstadoExpediente::Concluido->value])->save();
    $expediente->refresh();

    verificar('Ya tiene las horas de sobra', $horas->horasAprobadas($expediente) >= 20.0,
        (string) $horas->horasAprobadas($expediente));

    verificar('Y aun así NO está liberado: sigue en concluido',
        $expediente->estado === EstadoExpediente::Concluido);

    $faltan = $liberador->impedimentos($expediente);

    verificar('Las horas ya no estorban',
        ! collect($faltan)->contains(fn ($m) => str_contains($m, 'horas')),
        implode(' | ', $faltan));

    verificar('Pero el papeleo sí, así que sigue sin poderse liberar',
        ! $liberador->sePuedeLiberar($expediente) && $faltan !== []);

    verificar('Y emitir se rehúsa con la lista',
        rehusaCon(422, fn () => $liberador->liberar($expediente, $global), 'No se puede liberar todavía'));

    echo PHP_EOL.'4. Completado el papeleo, se libera'.PHP_EOL;

    $final = $expediente->informes()->whereHas('tipo', fn ($q) => $q->where('es_final', true))->firstOrFail();
    $papeleo->entregar($final, 'ruta/final.pdf', 'informe-final.pdf');
    $papeleo->revisar($final, true, null, $global);

    $papeleo->evaluar($expediente->refresh(), EvaluacionProceso::SUPERVISOR, null, [], 'Excelente.', $global);

    app(App\Services\ProcesosFormativos\SolicitudDelAlumno::class)
        ->guardarDocumento($expediente->refresh(), $papel->id, 'liberacion', 'ruta/carta-termino.pdf', 'carta.pdf');

    $expediente->excepciones()->create([
        'requisito' => 'convenio',
        'motivo' => 'Se autorizó con el convenio en trámite.',
        'autorizada_por' => $global->id,
        'autorizada_en' => now(),
    ]);
    $expediente->refresh()->load('excepciones.autorizadaPor.persona');

    verificar('Ya no falta nada', $liberador->sePuedeLiberar($expediente->refresh()),
        implode(' | ', $liberador->impedimentos($expediente)));

    $liberacion = $liberador->liberar($expediente, $global, '10.0.0.9');

    verificar('Se emitió con folio', $liberacion->folio !== '' && $liberacion->folio !== null,
        (string) $liberacion->folio);

    verificar('El folio lleva la clave del tipo y el año',
        str_starts_with($liberacion->folio, 'SERV-'.now()->year.'-'),
        $liberacion->folio);

    verificar('El expediente pasa a LIBERADO',
        $expediente->refresh()->estado === EstadoExpediente::Liberado);

    verificar('Y queda anotado en la bitácora, con su IP',
        $expediente->transiciones()->where('estado_destino', 'liberado')->first()?->ip === '10.0.0.9');

    verificar('Las horas se COPIAN, no se leen al mirarlas',
        (int) $liberacion->horas_acreditadas === 24, (string) $liberacion->horas_acreditadas);

    echo PHP_EOL.'5. Lo que dice el documento está CONGELADO'.PHP_EOL;

    verificar('El snapshot trae al alumno con su matrícula',
        $liberacion->delSnapshot('alumno.matricula') === $matricula->matricula);

    verificar('La organización con su razón social',
        $liberacion->delSnapshot('organizacion.razon_social') === PREFIJO.'Centro de asistencia');

    verificar('Y la regla con su VERSIÓN',
        (int) $liberacion->delSnapshot('regla.version') === 1
        && $liberacion->delSnapshot('regla.nombre') === PREFIJO.'Regla');

    /*
     * Y las EXCEPCIONES. Una constancia emitida perdonando un requisito tiene
     * que poder decir cuál y quién lo autorizó: sin ellas se ve idéntica a otra
     * que cumplió todo. El caso se construye —el expediente del escenario no
     * tenía ninguna— porque si no, quitarlas del snapshot no cambiaba nada.
     */
    verificar('Las excepciones viajan en el snapshot, con su motivo y su firma',
        collect($liberacion->delSnapshot('excepciones', []))
            ->contains(fn ($x) => str_contains((string) ($x['motivo'] ?? ''), 'convenio en trámite')
                && ($x['autorizada_por'] ?? null) !== null),
        json_encode($liberacion->delSnapshot('excepciones', []), JSON_UNESCAPED_UNICODE));

    /*
     * Y ahora se cambia el mundo por debajo: la organización se renombra y la
     * regla sube sus horas. El documento tiene que seguir diciendo lo mismo —es
     * lo único que separa un snapshot de una consulta—.
     */
    $organizacion->forceFill(['razon_social' => PREFIJO.'Cambiada después'])->save();
    $version->update(['horas_requeridas' => 999]);

    $liberacion->refresh();

    verificar('Renombrar la organización NO cambia el documento',
        $liberacion->delSnapshot('organizacion.razon_social') === PREFIJO.'Centro de asistencia');

    verificar('Ni subirle las horas a la regla',
        (int) $liberacion->delSnapshot('regla.horas_requeridas') === 20,
        (string) $liberacion->delSnapshot('regla.horas_requeridas'));

    $version->update(['horas_requeridas' => 20]);

    echo PHP_EOL.'6. No se libera dos veces'.PHP_EOL;

    verificar('Ya liberado, se dice con esas palabras',
        collect($liberador->impedimentos($expediente->refresh()))
            ->contains(fn ($m) => str_contains($m, 'ya está liberado')),
        implode(' | ', $liberador->impedimentos($expediente)));

    verificar('Y emitir otra se rehúsa',
        rehusaCon(422, fn () => $liberador->liberar($expediente, $global)));

    verificar('Sigue habiendo UNA sola',
        LiberacionProceso::query()->where('expediente_id', $expediente->id)->count() === 1);

    echo PHP_EOL.'7. El FOLIO es atómico y no se repite'.PHP_EOL;

    // Un segundo expediente, liberado igual: su folio tiene que ser el
    // siguiente, no el mismo.
    $otraMatricula = MatriculaOferta::query()
        ->whereKeyNot($matricula->id)
        ->whereHas('oferta', fn ($q) => $q->where('plan_id', $matricula->oferta->plan_id))
        ->firstOrFail();

    $segundo = $transiciones->abrir([
        'matricula_oferta_id' => $otraMatricula->id,
        'tipo_proceso_id' => $tipo->id,
        'regla_version_id' => $version->id,
        'horas_requeridas' => 20,
    ], $global);

    foreach ([EstadoExpediente::Solicitado, EstadoExpediente::EnRevision, EstadoExpediente::Aprobado] as $paso) {
        $segundo = $transiciones->mover($segundo, $paso, $global);
    }

    $segundo = $asignador->asignar($segundo, [
        'organizacion_id' => $organizacion->id,
        'fecha_inicio' => $inicio->toDateString(),
        'fecha_fin_programada' => $inicio->copy()->addMonths(3)->toDateString(),
    ], $global);

    $segundo = $transiciones->mover($segundo, EstadoExpediente::EnCurso, $global);

    foreach ([0, 1, 2] as $dia) {
        $horas->aprobar($horas->capturar($segundo->refresh(), [
            'fecha' => $inicio->copy()->addDays($dia)->toDateString(),
            'hora_inicio' => '09:00',
            'hora_fin' => '17:00',
            'actividad' => 'Jornada del segundo alumno, día '.$dia.'.',
        ], $global), $global);
    }

    $segundo = $transiciones->mover($segundo->refresh(), EstadoExpediente::Concluido, $global);

    $finalSegundo = $segundo->informes()->whereHas('tipo', fn ($q) => $q->where('es_final', true))->firstOrFail();
    $papeleo->entregar($finalSegundo, 'ruta/final2.pdf', 'final2.pdf');
    $papeleo->revisar($finalSegundo, true, null, $global);
    $papeleo->evaluar($segundo->refresh(), EvaluacionProceso::SUPERVISOR, null, [], 'Bien.', $global);

    // El mismo documento de liberación que se le pide al primero: comparten
    // regla, así que comparten requisitos.
    app(App\Services\ProcesosFormativos\SolicitudDelAlumno::class)
        ->guardarDocumento($segundo->refresh(), $papel->id, 'liberacion', 'ruta/carta2.pdf', 'carta2.pdf');

    $segundaLiberacion = $liberador->liberar($segundo->refresh(), $global);

    verificar('El segundo folio es DISTINTO del primero',
        $segundaLiberacion->folio !== $liberacion->folio,
        $liberacion->folio.' contra '.$segundaLiberacion->folio);

    verificar('Y es el siguiente consecutivo',
        (int) substr($segundaLiberacion->folio, -5) === (int) substr($liberacion->folio, -5) + 1,
        $liberacion->folio.' → '.$segundaLiberacion->folio);

    /*
     * Y el contador es POR TIPO Y AÑO, que es lo que lo separa de un
     * `MAX(folio)+1` o de un `count()+1`: un proceso de otro tipo arranca en 1
     * aunque ya existan liberaciones. Sin este caso las dos fórmulas dan el
     * mismo número y la mutación sobrevivía.
     */
    $otroTipo = TipoProcesoFormativo::query()->where('clave', 'practicas_profesionales')->firstOrFail();

    $reglaOtroTipo = ReglaProceso::create([
        'nombre' => PREFIJO.'Regla de prácticas',
        'tipo_proceso_id' => $otroTipo->id,
        'plan_id' => $matricula->oferta->plan_id,
    ]);

    /*
     * Sin requisitos: lo que se prueba aquí es el FOLIO, no la lista de
     * impedimentos. Las banderas se apagan EXPLÍCITAMENTE porque
     * `exige_evaluacion_supervisor` e `exige_informe_final` vienen encendidas
     * por omisión en la base — omitirlas dejaba a este expediente pidiendo un
     * papeleo que el caso no viene a probar.
     */
    $versionOtroTipo = $reglaOtroTipo->versiones()->create([
        'version' => 1,
        'vigente_desde' => now()->subYear()->toDateString(),
        'obligatorio' => true,
        'exige_evaluacion_supervisor' => false,
        'exige_evaluacion_estudiante' => false,
        'exige_informe_final' => false,
    ]);

    $dePracticas = $transiciones->abrir([
        'matricula_oferta_id' => $matricula->id,
        'tipo_proceso_id' => $otroTipo->id,
        'regla_version_id' => $versionOtroTipo->id,
    ], $global);

    foreach ([EstadoExpediente::Solicitado, EstadoExpediente::EnRevision, EstadoExpediente::Aprobado,
        EstadoExpediente::Asignado, EstadoExpediente::EnCurso, EstadoExpediente::Concluido] as $paso) {
        $dePracticas = $transiciones->mover($dePracticas, $paso, $global);
    }

    $liberacionPracticas = $liberador->liberar($dePracticas, $global);

    verificar('Otro tipo arranca su propio consecutivo en 1',
        str_ends_with($liberacionPracticas->folio, '-00001')
        && str_starts_with($liberacionPracticas->folio, 'PRAC-'),
        $liberacionPracticas->folio);

    echo PHP_EOL.'8. Corregir EMITE otra: las dos se conservan'.PHP_EOL;

    verificar('Sin motivo se rehúsa',
        rehusaCon(422, fn () => $liberador->corregir($liberacion, '   ', $global), 'hace falta escribir por qué'));

    $correccion = $liberador->corregir($liberacion, 'Se capturó mal la organización receptora.', $global);

    verificar('La nueva apunta a la anterior',
        (int) $correccion->liberacion_corregida_id === $liberacion->id);

    verificar('Con su motivo escrito',
        $correccion->motivo_correccion === 'Se capturó mal la organización receptora.');

    verificar('La anterior NO se borró: sigue ahí',
        LiberacionProceso::query()->whereKey($liberacion->id)->exists());

    verificar('Pero quedó sin efecto, con su fecha',
        ! $liberacion->refresh()->estaVigente() && $liberacion->corregida_en !== null);

    verificar('Y ahora hay DOS del mismo expediente',
        LiberacionProceso::query()->where('expediente_id', $expediente->id)->count() === 2);

    verificar('Sólo una vigente',
        LiberacionProceso::query()->where('expediente_id', $expediente->id)->vigentes()->count() === 1);

    verificar('Y es la que el servicio contesta',
        $liberador->vigenteDe($expediente)?->id === $correccion->id);

    /*
     * Y `vigenteDe` FILTRA de verdad: no se limita a devolver la más reciente.
     *
     * El caso se construye jubilando la última sin emitir otra —lo que dejaría
     * una limpieza, una corrección a mano o un comando futuro—. Sin él las dos
     * fórmulas dan lo mismo, porque corregir siempre crea la nueva al final, y
     * la mutación sobrevivía.
     */
    $correccion->forceFill(['corregida_en' => now()])->save();

    verificar('Con TODAS corregidas, no contesta ninguna',
        $liberador->vigenteDe($expediente) === null);

    $correccion->forceFill(['corregida_en' => null])->save();

    verificar('Y al devolverle la vigencia, vuelve a contestarla',
        $liberador->vigenteDe($expediente)?->id === $correccion->id);

    verificar('La corrección tiene su propio folio',
        $correccion->folio !== $liberacion->folio);

    /*
     * Corregir una ya corregida se rehúsa — y el caso va con el reloj ADELANTADO
     * a propósito.
     *
     * Sin eso el mutante sobrevive por una coincidencia: `update()` de MySQL
     * cuenta filas CAMBIADAS, no coincidentes, y dentro del mismo segundo
     * reescribir `corregida_en` y `updated_at` con los mismos valores devuelve
     * cero — así que el guard de «ya la corrigió alguien» saltaba igual aunque
     * el `whereNull` no estuviera. Con el tiempo movido, la única defensa es el
     * filtro, que es lo que se quiere comprobar. Medido antes de escribirlo.
     */
    Illuminate\Support\Carbon::setTestNow(now()->addMinute());

    verificar('Corregir una ya corregida se rehúsa',
        rehusaCon(422, fn () => $liberador->corregir($liberacion->refresh(), 'otra vez', $global),
            'ya la corrigió alguien'));

    verificar('Y no se creó una tercera',
        LiberacionProceso::query()->where('expediente_id', $expediente->id)->count() === 2,
        (string) LiberacionProceso::query()->where('expediente_id', $expediente->id)->count());

    Illuminate\Support\Carbon::setTestNow();

    echo PHP_EOL.'9. La columna generada y la lista de vigentes dicen lo mismo'.PHP_EOL;

    $expresion = DB::selectOne(
        "SELECT GENERATION_EXPRESSION g FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'liberaciones_proceso'
           AND COLUMN_NAME = 'expediente_si_vigente'"
    )?->g ?? '';

    verificar('La columna generada existe y mira `corregida_en`',
        str_contains($expresion, 'corregida_en'), substr($expresion, 0, 90));

    verificar('La corregida sale con la columna en NULL',
        DB::table('liberaciones_proceso')->where('id', $liberacion->id)->value('expediente_si_vigente') === null);

    verificar('Y la vigente, con el id de su expediente',
        (int) DB::table('liberaciones_proceso')->where('id', $correccion->id)->value('expediente_si_vigente')
            === $expediente->id);

    echo PHP_EOL.'10. Los permisos: liberar ≠ corregir'.PHP_EOL;

    $revisor = usuarioConRol('administrativo');

    verificar('Quien no libera, no libera',
        rehusaCon(403, fn () => $liberador->liberar($segundo->refresh(), $revisor), 'no puede liberar'));

    verificar('Y quien no corrige, no corrige',
        rehusaCon(403, fn () => $liberador->corregir($correccion, 'porque sí', $revisor),
            'no puede corregir'));

    /*
     * Y la TRANSICIÓN a «liberado» pide su propio permiso, no el de revisar.
     *
     * Es la última defensa si algún día alguien llama `mover()` sin pasar por el
     * liberador —donde el permiso ya se comprobó—. Sin este caso la tabla de
     * `TransicionDeExpediente::PERMISOS` podía apuntar a `revisar-solicitudes`
     * sin que ninguna suite lo notara.
     */
    $soloRevisa = usuarioConRol('director_general');
    $soloRevisa->rolActivo->revokePermissionTo('liberar-expedientes-formativos');
    $soloRevisa = $soloRevisa->fresh(['persona', 'rolActivo']);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $paraMover = $transiciones->abrir([
        'matricula_oferta_id' => MatriculaOferta::query()
            ->whereKeyNot($matricula->id)
            ->whereKeyNot($otraMatricula->id)
            ->value('id'),
        'tipo_proceso_id' => $otroTipo->id,
        'regla_version_id' => $versionOtroTipo->id,
    ], $global);

    foreach ([EstadoExpediente::Solicitado, EstadoExpediente::EnRevision, EstadoExpediente::Aprobado,
        EstadoExpediente::Asignado, EstadoExpediente::EnCurso, EstadoExpediente::Concluido] as $paso) {
        $paraMover = $transiciones->mover($paraMover, $paso, $global);
    }

    verificar('Mover a «liberado» exige el permiso de LIBERAR, no el de revisar',
        rehusaCon(403, fn () => $transiciones->mover($paraMover, EstadoExpediente::Liberado, $soloRevisa),
            'Tu rol no puede liberar'));

    $soloRevisa->rolActivo->givePermissionTo('liberar-expedientes-formativos');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    /*
     * Y el ALCANCE, que es otra cosa que el permiso: quien LO TIENE pero está
     * acotado a otro campus tampoco libera. Sin este caso, quitar la
     * comprobación de alcance no cambiaba ningún resultado — el único usuario
     * sin permiso ya se detenía antes.
     */
    $otroCampus = App\Models\Academico\Campus::query()
        ->whereKeyNot($matricula->oferta->campus_id)
        ->firstOrFail();

    $acotado = usuarioConRol('director_general');
    $acotado->persona->asignacionesRol()->update(['campus_id' => $otroCampus->id]);
    $acotado = $acotado->fresh(['persona.asignacionesRol', 'rolActivo']);

    verificar('Con permiso pero acotado a OTRO campus, no libera',
        rehusaCon(403, fn () => $liberador->liberar($segundo->refresh(), $acotado),
            'campus que tu rol no alcanza'));

    verificar('Ni corrige',
        rehusaCon(403, fn () => $liberador->corregir($correccion, 'desde otro campus', $acotado),
            'campus que tu rol no alcanza'));

    /*
     * Y el caso que separa los DOS permisos: alguien con el de liberar pero sin
     * el de corregir. Sin él, quitar la separación no cambiaría nada — el único
     * usuario sin permiso ya se detenía en los dos.
     */
    $soloLibera = usuarioConRol('director_general');
    $soloLibera->rolActivo->revokePermissionTo('corregir-liberacion-formativa');
    $soloLibera = $soloLibera->fresh(['persona', 'rolActivo']);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    verificar('Con el permiso de liberar pero sin el de corregir, no corrige',
        rehusaCon(403, fn () => $liberador->corregir($correccion, 'con permiso a medias', $soloLibera),
            'no puede corregir'));

    $soloLibera->rolActivo->givePermissionTo('corregir-liberacion-formativa');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    echo PHP_EOL.'11. La CONSTANCIA sale del snapshot'.PHP_EOL;

    $pdf = $constancia->generar($correccion->load('expediente', 'corrige'));

    verificar('Es un PDF de verdad', str_starts_with($pdf, '%PDF'));

    verificar('Y pesa algo', strlen($pdf) > 2000, strlen($pdf).' bytes');

    $vieja = $constancia->generar($liberacion->refresh()->load('expediente', 'corrige'));

    verificar('La corregida también se puede reimprimir',
        str_starts_with($vieja, '%PDF'));

    /*
     * Y sale DISTINTA: la jubilada lleva marca de agua y su aviso. Reimprimir la
     * vieja con el mismo aspecto que la vigente sería entregar dos documentos
     * indistinguibles del mismo expediente.
     */
    verificar('Pero NO idéntica a la vigente', $vieja !== $pdf);

    /*
     * La marca de agua de la jubilada y el folio del pie se miden sobre el HTML
     * que recibe el motor —dentro del PDF el texto va como índices de glifo de
     * una fuente subconjuntada y no hay nada que buscar, la lección de
     * `HistorialPdfTest`—. Comparar los dos PDF a secas no bastaba: difieren
     * por el folio de todos modos, y la mutación sobrevivía.
     */
    $espia = new class extends App\Documentos\DocumentoPdf
    {
        public array $opciones = [];

        public string $html = '';

        public function generar(string $html, array $opciones = []): string
        {
            $this->html = $html;
            $this->opciones = $opciones;

            return '%PDF-espia';
        }
    };

    $conEspia = new ConstanciaFormativa($espia);

    $conEspia->generar($correccion);

    verificar('La VIGENTE sale sin marca de agua', ($espia->opciones['marca_agua'] ?? null) === null);

    verificar('Y su pie lleva el folio y la hoja',
        str_contains($espia->opciones['pie'] ?? '', $correccion->folio)
        && str_contains($espia->opciones['pie'] ?? '', '{PAGENO}'));

    $conEspia->generar($liberacion->refresh());

    verificar('La CORREGIDA sale con marca de agua',
        ($espia->opciones['marca_agua'] ?? null) === 'SIN EFECTO');

    verificar('Y su pie avisa de que quedó sin efecto',
        str_contains($espia->opciones['pie'] ?? '', 'sin efecto')
        && str_contains($espia->opciones['pie'] ?? '', $liberacion->folio));

    verificar('El cuerpo sale del SNAPSHOT: nombra al alumno y a su organización',
        str_contains($espia->html, (string) $liberacion->delSnapshot('alumno.nombre'))
        && str_contains($espia->html, PREFIJO.'Centro de asistencia'));

    verificar('El nombre del archivo lleva el folio',
        str_contains($constancia->nombreDeArchivo($correccion), str_replace('/', '-', $correccion->folio)),
        $constancia->nombreDeArchivo($correccion));

    echo PHP_EOL.'12. `RequisitoFormativo` contesta, y NO cablea nada'.PHP_EOL;

    verificar('El plan lo exige: obligatorio Y cuenta para titulación',
        $requisito->exigeElPlan($matricula, 'servicio_social'));

    verificar('Y ya está liberado', $requisito->estaLiberado($matricula, 'servicio_social'));

    verificar('Sin impedimentos', $requisito->impedimentos($matricula, 'servicio_social') === [],
        implode(' | ', $requisito->impedimentos($matricula, 'servicio_social')));

    $constanciaDatos = $requisito->constanciaDe($matricula, 'servicio_social');

    verificar('La constancia que devuelve es la VIGENTE, no la corregida',
        $constanciaDatos['folio'] === $correccion->folio,
        $constanciaDatos['folio'].' contra '.$correccion->folio);

    /*
     * Y su snapshot es el de la CORRECCIÓN, que se rehizo al emitirla — no una
     * copia del de la original.
     *
     * Es lo correcto y conviene tenerlo escrito: se corrige justamente porque
     * algo estaba mal, así que copiar el snapshot viejo repetiría el error y la
     * corrección no serviría de nada. Aquí se ve porque la organización se
     * renombró entre las dos: la primera dice «Centro de asistencia» y la
     * segunda «Cambiada después».
     */
    verificar('Con sus horas, del snapshot de la vigente',
        (int) $constanciaDatos['horas'] === 24, (string) $constanciaDatos['horas']);

    verificar('Y la corrección congela lo de HOY, no una copia de la original',
        $constanciaDatos['organizacion'] === PREFIJO.'Cambiada después'
        && $liberacion->refresh()->delSnapshot('organizacion.razon_social') === PREFIJO.'Centro de asistencia',
        $constanciaDatos['organizacion']);

    verificar('Un tipo que no existe contesta que no exige nada',
        ! $requisito->exigeElPlan($matricula, 'no_existe_este_tipo')
        && $requisito->impedimentos($matricula, 'no_existe_este_tipo') === []);

    /*
     * `cuenta_para_titulacion` apagado: le TOCA hacerlo, pero su falta no
     * detiene el título. Son dos cosas distintas y hay que poder separarlas.
     */
    $version->update(['cuenta_para_titulacion' => false]);
    $expediente->refresh()->load('reglaVersion');

    verificar('Con `cuenta_para_titulacion` apagado, deja de exigirlo',
        ! $requisito->exigeElPlan($matricula, 'servicio_social'));

    $version->update(['cuenta_para_titulacion' => true]);

    /*
     * Y la OTRA mitad: `obligatorio` apagado. Son dos cosas distintas —«le
     * toca hacerlo» y «su falta impide el título»— y hacen falta las dos. Sin
     * este caso, quitar cualquiera de las dos condiciones daba el mismo
     * resultado.
     */
    $version->update(['obligatorio' => false]);
    $expediente->refresh()->load('reglaVersion');

    verificar('Con `obligatorio` apagado, tampoco lo exige',
        ! $requisito->exigeElPlan($matricula, 'servicio_social'));

    $version->update(['obligatorio' => true]);
    $expediente->refresh()->load('reglaVersion');

    /*
     * Un expediente VIVO pero NO liberado no satisface nada, y se dice en qué
     * estado está. El caso es el de prácticas, que está concluido y sin
     * liberar... salvo que ya se liberó arriba, así que se usa uno nuevo.
     */
    $enCurso = $transiciones->abrir([
        'matricula_oferta_id' => $otraMatricula->id,
        'tipo_proceso_id' => $otroTipo->id,
        'regla_version_id' => $versionOtroTipo->id,
    ], $global);

    $versionOtroTipo->update(['cuenta_para_titulacion' => true]);
    $enCurso->refresh()->load('reglaVersion');

    verificar('Un expediente abierto pero SIN liberar no satisface el requisito',
        ! $requisito->estaLiberado($otraMatricula, 'practicas_profesionales'));

    verificar('Y se dice en qué estado está, con lo que le falta',
        collect($requisito->impedimentos($otraMatricula, 'practicas_profesionales'))
            ->contains(fn ($m) => str_contains($m, 'Borrador')),
        implode(' | ', $requisito->impedimentos($otraMatricula, 'practicas_profesionales')));

    /*
     * Y sin expediente NINGUNO se dice con esas palabras. «No cumple» sobre
     * alguien que no ha empezado no le dice qué hacer.
     */
    /*
     * Una matrícula CUALQUIERA sin expediente. No hace falta que sea del mismo
     * plan —sólo hay dos de ése—: lo que se prueba es que el impedimento nombre
     * la falta de expediente, y para eso hay que asegurarse de que la regla le
     * alcance, así que se crea una GENERAL sin ejes.
     */
    $reglaGeneral = ReglaProceso::create([
        'nombre' => PREFIJO.'General',
        'tipo_proceso_id' => $tipo->id,
    ]);

    $reglaGeneral->versiones()->create([
        'version' => 1,
        'vigente_desde' => now()->subYear()->toDateString(),
        'obligatorio' => true,
        'cuenta_para_titulacion' => true,
    ]);

    $sinExpediente = MatriculaOferta::query()
        ->whereKeyNot($matricula->id)
        ->whereKeyNot($otraMatricula->id)
        ->first();

    verificar('Hay una tercera matrícula sin expediente', $sinExpediente !== null);

    verificar('Sin expediente abierto se dice con esas palabras',
        collect($requisito->impedimentos($sinExpediente, 'servicio_social'))
            ->contains(fn ($m) => str_contains($m, 'todavía no ha abierto su expediente')),
        implode(' | ', $requisito->impedimentos($sinExpediente, 'servicio_social')));

    echo PHP_EOL.'13. Y titulación sigue SIN conocerlo'.PHP_EOL;

    /*
     * El pedido del cliente decía «integra con titulación» y «NO modifiques esos
     * procesos». Esto lo fija: el día que alguien lo enganche sin decidirlo, la
     * suite lo dice.
     */
    $consumidores = ['app/Services/Emision/ValidadorTitulo.php', 'app/Services/EstadoCertificacion.php'];

    foreach ($consumidores as $archivo) {
        $ruta = base_path($archivo);

        if (! file_exists($ruta)) {
            continue;
        }

        verificar(basename($archivo).' no conoce RequisitoFormativo',
            ! str_contains(file_get_contents($ruta), 'RequisitoFormativo'));
    }

    verificar('Y nadie en app/ lo usa todavía: es el lado que PREGUNTA',
        (function () {
            $usos = [];

            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('app'))) as $f) {
                if ($f->isFile() && $f->getExtension() === 'php'
                    && $f->getFilename() !== 'RequisitoFormativo.php'
                    && str_contains(file_get_contents($f->getPathname()), 'RequisitoFormativo')) {
                    $usos[] = $f->getFilename();
                }
            }

            return $usos === [];
        })());

    echo PHP_EOL.'14. Un expediente ajeno no da su constancia'.PHP_EOL;

    $controlador = app(LiberacionFormativaController::class);

    $peticion = Illuminate\Http\Request::create('/', 'GET');
    $peticion->setUserResolver(fn () => $global);

    verificar('La liberación de otro expediente da 404',
        rehusaCon(404, fn () => $controlador->constancia($peticion, $segundo, $correccion),
            'no es de este expediente'));

    $alumnoAjeno = usuarioConRol('alumno', (int) $otraMatricula->persona_id);

    $peticionAjena = Illuminate\Http\Request::create('/', 'GET');
    $peticionAjena->setUserResolver(fn () => $alumnoAjeno);

    verificar('Y un alumno que no es su dueño, tampoco',
        rehusaCon(404, fn () => $controlador->constancia($peticionAjena, $expediente, $correccion),
            'no es tuyo'));

    echo PHP_EOL.'15. La pantalla ofrece el botón sólo si de verdad se puede'.PHP_EOL;

    $props = function (ExpedienteProceso $e) use ($expedientes, $global) {
        $p = Illuminate\Http\Request::create('/', 'GET');
        $p->headers->set('X-Inertia', 'true');
        $p->headers->set('X-Inertia-Version', '');
        app()->instance('request', $p);
        $p->setUserResolver(fn () => $global);

        return json_decode($expedientes->show($p, $e)->toResponse($p)->getContent(), true)['props'];
    };

    $delLiberado = $props($expediente->refresh());

    verificar('Un liberado ya no ofrece el botón',
        $delLiberado['expediente']['se_puede_liberar'] === false);

    verificar('Y enseña sus DOS liberaciones, la vigente primero',
        count($delLiberado['expediente']['liberaciones']) === 2
        && $delLiberado['expediente']['liberaciones'][0]['vigente'] === true);

    verificar('La lista de lo que falta sale del MISMO servicio',
        $delLiberado['expediente']['papeleo_pendiente'] === $liberador->impedimentos($expediente));

} catch (Throwable $falla) {
    $verificaciones++;
    $fallidas++;
    echo "  \033[31mFALLA\033[39m la suite murió antes de terminar: ".$falla->getMessage()
        .' ('.basename($falla->getFile()).':'.$falla->getLine().')'.PHP_EOL;
} finally {
    $db->rollBack();

    echo PHP_EOL.'Resultado: '.($verificaciones - $fallidas)." correctas, {$fallidas} fallidas".PHP_EOL;
}
