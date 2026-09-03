<?php

/**
 * Reglas versionadas y elegibilidad (fase 3). Con rollback.
 *
 * Se corre con `php scripts/prueba-procesos-reglas.php` desde la raíz.
 *
 * ── Qué se vigila, y por qué ──────────────────────────────────────────────
 *  1. **Gana la MÁS específica**, con una jerarquía lexicográfica: declarar el
 *     PLAN gana sobre cualquier combinación de ejes menos específicos. Sin esa
 *     propiedad, «campus + modalidad» le ganaría a «este plan» y nadie podría
 *     explicar por qué.
 *  2. **Lo que se deja en null NO acota**, y el eje declarado sí: es lo que
 *     hace que una regla general y una excepción convivan.
 *  3. **La VERSIÓN es la vigente a una fecha**, y una futura no rige todavía.
 *     Es lo que permitirá congelar en el expediente lo que se le aplicó.
 *  4. **La elegibilidad falla CERRADO**: sin regla, no es elegible, y lo dice
 *     con esas palabras. Al revés, una escuela que aún no configura nada
 *     dejaría a todos solicitar y el primer expediente se abriría sin requisito.
 *  5. **Devuelve la LISTA de lo que falta, no un sí o un no.** «No eres
 *     elegible» manda a la gente a ventanilla; «te faltan 12 créditos y el
 *     seguro» se puede resolver. Y de uno en uno alguien arreglaría los
 *     créditos, reintentaría, y se enteraría del seguro.
 *  6. **Las guardas que impiden una regla que no alcanza a nadie**: rango de
 *     generaciones al revés, plan de otro programa, tolerancia que se traga las
 *     horas, informes sin periodicidad. Ninguna falla sola: la regla se guarda
 *     y no sirve.
 */

use App\Exceptions\AvisoParaElUsuario;
use App\Http\Controllers\ProcesosFormativos\MiProcesoFormativoController;
use App\Http\Controllers\ProcesosFormativos\ReglaProcesoController;
use App\Models\Academico\Campus;
use App\Models\Academico\PlanEstudio;
use App\Models\Academico\PlanMateria;
use App\Models\Academico\ProgramaAcademico;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\Admisiones\SituacionAlumno;
use App\Models\ControlEscolar\Historial;
use App\Models\Finanzas\SituacionPago;
use App\Models\Identidad\Persona;
use App\Models\Identidad\Rol;
use App\Models\Identidad\Usuario;
use App\Models\ProcesosFormativos\ReglaProceso;
use App\Models\ProcesosFormativos\ReglaProcesoVersion;
use App\Models\ProcesosFormativos\TipoProcesoFormativo;
use App\Models\Tenant;
use App\Services\ProcesosFormativos\ElegibilidadFormativa;
use App\Services\ProcesosFormativos\ResolutorDeRegla;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

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

function peticionCon(array $datos, ?Usuario $como = null, string $metodo = 'POST'): Illuminate\Http\Request
{
    $p = Illuminate\Http\Request::create('/', $metodo, $datos);

    $p->setUserResolver(fn () => $como ?? auth()->user());

    return $p;
}

function props(object $controlador, string $metodo, Usuario $como, array $query = [], array $extra = []): array
{
    $peticion = Illuminate\Http\Request::create('/', 'GET', $query);
    $peticion->headers->set('X-Inertia', 'true');
    $peticion->headers->set('X-Inertia-Version', '');

    app()->instance('request', $peticion);
    $peticion->setUserResolver(fn () => $como);

    $respuesta = $controlador->{$metodo}($peticion, ...$extra);

    return json_decode($respuesta->toResponse($peticion)->getContent(), true)['props'];
}

/**
 * Una cuenta con ese rol activo. Si la persona YA tiene cuenta —el alumno del
 * demo la tiene— se reusa: `usuarios.persona_id` es único, así que crear otra
 * revienta con un 1062 y la suite moriría por su propio escenario.
 */
function usuarioConRol(string $rol, ?int $personaId = null): Usuario
{
    $personaId ??= Persona::create([
        'nombre' => 'Prueba',
        'primer_apellido' => 'Reglas',
        'segundo_apellido' => (string) random_int(1000, 9999),
        'sexo_id' => 1,
    ])->id;

    $rolId = Rol::where('name', $rol)->firstOrFail()->id;

    $cuenta = Usuario::query()->where('persona_id', $personaId)->first();

    $cuenta
        ? $cuenta->forceFill(['rol_activo_id' => $rolId])->save()
        : $cuenta = Usuario::create([
            'persona_id' => $personaId,
            'usuario' => 'prueba_reg_'.random_int(100000, 999999),
            'email' => 'prueba_reg_'.random_int(100000, 999999).'@ejemplo.mx',
            'password' => Hash::make('secreto12345'),
            'rol_activo_id' => $rolId,
        ]);

    $cuenta->persona->asignacionesRol()->firstOrCreate(
        ['rol_id' => $rolId],
        ['activo' => true, 'campus_id' => null],
    );

    return $cuenta->fresh(['persona', 'rolActivo']);
}

/** Una versión con lo mínimo, y encima lo que se le pase. */
function versionDe(ReglaProceso $regla, array $encima = []): ReglaProcesoVersion
{
    return $regla->versiones()->create(array_merge([
        'version' => (int) $regla->versiones()->max('version') + 1,
        'vigente_desde' => now()->subYear()->toDateString(),
        'obligatorio' => true,
    ], $encima));
}

const PREFIJO = 'ZZREGLA-';

$db->beginTransaction();

try {
    $controlador = app(ReglaProcesoController::class);
    $portal = app(MiProcesoFormativoController::class);
    $resolutor = app(ResolutorDeRegla::class);
    $elegibilidad = app(ElegibilidadFormativa::class);

    $global = usuarioConRol('director_general');
    auth()->login($global);

    /*
     * Se parte de CERO reglas, dentro de la transacción.
     *
     * No es cosmético: lo que se prueba es CUÁL GANA, y eso sólo se puede
     * afirmar sabiendo cuáles existen. Pasaba corriéndola sola y se cayó en
     * cuanto configuré la primera regla desde la pantalla —una de Derecho, que
     * es el programa del protagonista, así que competía y ganaba—. Es la sexta
     * vez que este proyecto se cobra lo mismo.
     */
    DB::table('reglas_proceso')->delete();

    $tipo = TipoProcesoFormativo::query()->where('clave', 'servicio_social')->firstOrFail();
    $otroTipo = TipoProcesoFormativo::query()->where('clave', 'practicas_profesionales')->firstOrFail();

    /*
     * El protagonista se ELIGE con lo que hace falta para medir —plan con
     * créditos, historial, generación y materias todavía sin aprobar— y lo que
     * el demo no tiene se CONSTRUYE.
     *
     * Y no lo tiene: **ninguna de las 32 matrículas del demo tiene
     * `periodo_actual` capturado**, así que sin ponerlo aquí todas las
     * comprobaciones de elegibilidad pasarían por la razón equivocada — la
     * primera versión de esta suite daba «no es elegible» por el periodo
     * ausente en vez de por lo que estaba probando. Se le pone dentro de la
     * transacción y se deshace con el rollback.
     */
    $matricula = MatriculaOferta::query()
        ->whereHas('oferta.plan', fn ($q) => $q->whereNotNull('total_creditos')->where('total_creditos', '>', 0))
        ->whereHas('historial')
        ->whereNotNull('generacion')
        ->with('oferta.plan', 'oferta.programaAcademico', 'situacion')
        ->get()
        ->first(function (MatriculaOferta $m) {
            $aprobadas = DB::table('historial')
                ->where('matricula_oferta_id', $m->id)
                ->whereNull('deleted_at')
                ->pluck('plan_materia_id')
                ->filter()
                ->all();

            return PlanMateria::query()
                ->where('plan_id', $m->oferta?->plan_id)
                ->whereNotIn('id', $aprobadas ?: [0])
                ->exists();
        });

    verificar('Hay una matrícula con plan, historial, generación y materias pendientes',
        $matricula !== null);

    $matricula->forceFill(['periodo_actual' => 5])->save();
    $matricula->refresh()->load('oferta.plan', 'oferta.programaAcademico', 'situacion');

    $oferta = $matricula->oferta;
    $otroCampus = Campus::query()->whereKeyNot($oferta->campus_id)->firstOrFail();
    $otroPrograma = ProgramaAcademico::query()->whereKeyNot($oferta->programa_academico_id)->firstOrFail();

    echo PHP_EOL.'1. Gana la MÁS específica, y la jerarquía es lexicográfica'.PHP_EOL;

    $general = ReglaProceso::create([
        'nombre' => PREFIJO.'General',
        'tipo_proceso_id' => $tipo->id,
    ]);

    $delCampus = ReglaProceso::create([
        'nombre' => PREFIJO.'Del campus',
        'tipo_proceso_id' => $tipo->id,
        'campus_id' => $oferta->campus_id,
    ]);

    versionDe($general, ['horas_requeridas' => 480]);
    versionDe($delCampus, ['horas_requeridas' => 400]);

    verificar('Con dos que alcanzan, gana la del campus',
        $resolutor->reglaPara($matricula, $tipo)?->id === $delCampus->id);

    $delPrograma = ReglaProceso::create([
        'nombre' => PREFIJO.'Del programa',
        'tipo_proceso_id' => $tipo->id,
        'programa_academico_id' => $oferta->programa_academico_id,
    ]);
    versionDe($delPrograma, ['horas_requeridas' => 300]);

    verificar('Y el programa le gana al campus',
        $resolutor->reglaPara($matricula, $tipo)?->id === $delPrograma->id);

    /*
     * Y el caso que hace falta para que la generación PESE: dos reglas idénticas
     * salvo que una acota la generación.
     *
     * La que acota se crea PRIMERO a propósito. Al revés ganaría igual por el
     * desempate de id —es la más reciente— y quitarle su peso a la generación
     * no cambiaría ningún resultado: la mutación sobrevivía y la regla se
     * quedaba sin comprobar.
     */
    $delProgramaYGeneracion = ReglaProceso::create([
        'nombre' => PREFIJO.'Del programa, de su generación',
        'tipo_proceso_id' => $tipo->id,
        'programa_academico_id' => $oferta->programa_academico_id,
        'generacion_desde' => $matricula->generacion,
        'generacion_hasta' => $matricula->generacion,
    ]);
    versionDe($delProgramaYGeneracion, ['horas_requeridas' => 250]);

    $delProgramaSinGeneracion = ReglaProceso::create([
        'nombre' => PREFIJO.'Del programa, sin acotar generación',
        'tipo_proceso_id' => $tipo->id,
        'programa_academico_id' => $oferta->programa_academico_id,
    ]);
    versionDe($delProgramaSinGeneracion, ['horas_requeridas' => 260]);

    verificar('Acotar la generación gana, aunque la otra sea más reciente',
        $resolutor->reglaPara($matricula, $tipo)?->id === $delProgramaYGeneracion->id,
        (string) $resolutor->reglaPara($matricula, $tipo)?->nombre);

    $delProgramaSinGeneracion->forceFill(['activa' => false])->save();

    /*
     * EL caso que separa una suma cualquiera de una jerarquía: el PLAN solo
     * contra campus + modalidad + generación juntos. Si los pesos no fueran
     * lexicográficos, ganaría el montón.
     */
    $delPlan = ReglaProceso::create([
        'nombre' => PREFIJO.'Del plan',
        'tipo_proceso_id' => $tipo->id,
        'plan_id' => $oferta->plan_id,
    ]);
    versionDe($delPlan, ['horas_requeridas' => 200]);

    $delMonton = ReglaProceso::create([
        'nombre' => PREFIJO.'Campus + modalidad + generación',
        'tipo_proceso_id' => $tipo->id,
        'campus_id' => $oferta->campus_id,
        'modalidad' => $oferta->modalidad,
        'generacion_desde' => '1900',
        'generacion_hasta' => '2999',
    ]);
    versionDe($delMonton, ['horas_requeridas' => 100]);

    verificar('El PLAN gana aunque el otro declare más ejes',
        $resolutor->reglaPara($matricula, $tipo)?->id === $delPlan->id,
        (string) $resolutor->reglaPara($matricula, $tipo)?->nombre);

    verificar('Y las candidatas salen ordenadas de la que gana a la que no',
        $resolutor->candidatas($matricula, $tipo)->first()->id === $delPlan->id
        && $resolutor->candidatas($matricula, $tipo)->last()->id === $general->id);

    echo PHP_EOL.'2. Lo declarado acota; lo que está en null, no'.PHP_EOL;

    $deOtroCampus = ReglaProceso::create([
        'nombre' => PREFIJO.'De otro campus',
        'tipo_proceso_id' => $tipo->id,
        'campus_id' => $otroCampus->id,
    ]);

    verificar('Una regla de otro campus NO alcanza', ! $deOtroCampus->alcanzaA($matricula));

    $deOtroPrograma = ReglaProceso::create([
        'nombre' => PREFIJO.'De otro programa',
        'tipo_proceso_id' => $tipo->id,
        'programa_academico_id' => $otroPrograma->id,
    ]);

    verificar('Ni una de otro programa', ! $deOtroPrograma->alcanzaA($matricula));

    verificar('Y la general alcanza a cualquiera', $general->alcanzaA($matricula));

    verificar('Una regla de OTRO TIPO no se mezcla',
        $resolutor->candidatas($matricula, $otroTipo)->isEmpty());

    /*
     * Una regla APAGADA no compite. Sin ese filtro, apagarla no serviría de
     * nada: seguiría ganándole a la general por ser más específica, y la
     * escuela no tendría forma de retirar una excepción sin borrarla.
     */
    $delPlan->forceFill(['activa' => false])->save();

    verificar('Una regla apagada no compite',
        $resolutor->reglaPara($matricula, $tipo)?->id !== $delPlan->id);

    $delPlan->forceFill(['activa' => true])->save();

    verificar('Y al encenderla vuelve a ganar',
        $resolutor->reglaPara($matricula, $tipo)?->id === $delPlan->id);

    /*
     * DOS reglas con la misma especificidad: gana la más reciente, y siempre la
     * misma. Sin desempate, el orden lo decidiría la base y la misma pregunta
     * daría dos respuestas distintas en dos días.
     */
    $gemela = ReglaProceso::create([
        'nombre' => PREFIJO.'Del plan (gemela)',
        'tipo_proceso_id' => $tipo->id,
        'plan_id' => $oferta->plan_id,
    ]);
    versionDe($gemela, ['horas_requeridas' => 150]);

    verificar('Con dos igual de específicas gana la más reciente, y siempre la misma',
        $resolutor->reglaPara($matricula, $tipo)?->id === $gemela->id
        && $resolutor->reglaPara($matricula, $tipo)?->id === $gemela->id);

    $gemela->forceFill(['activa' => false])->save();

    echo PHP_EOL.'3. El rango de generaciones'.PHP_EOL;

    $generacion = $matricula->generacion;

    verificar('Hay generación capturada para poder medir', $generacion !== null && $generacion !== '');

    $dentro = new ReglaProceso(['generacion_desde' => $generacion, 'generacion_hasta' => $generacion]);
    $antes = new ReglaProceso(['generacion_desde' => '9999']);
    $despues = new ReglaProceso(['generacion_hasta' => '1000']);
    $abierta = new ReglaProceso;

    verificar('Dentro del rango, alcanza', $dentro->cubreLaGeneracion($generacion));
    verificar('Antes del inicio, no', ! $antes->cubreLaGeneracion($generacion));
    verificar('Después del fin, no', ! $despues->cubreLaGeneracion($generacion));
    verificar('Sin rango, alcanza a cualquiera', $abierta->cubreLaGeneracion($generacion));

    /*
     * Sin generación capturada, una regla que la acote NO alcanza. Darla por
     * buena dejaría entrar a quien no sabemos de qué generación es, que es
     * justo lo que el rango existe para separar.
     */
    verificar('Sin generación capturada, una regla que la acota no alcanza',
        ! $dentro->cubreLaGeneracion(null) && ! $dentro->cubreLaGeneracion(''));

    verificar('Pero la que no la acota, sí', $abierta->cubreLaGeneracion(null));

    echo PHP_EOL.'4. La versión vigente a una fecha'.PHP_EOL;

    $reglaVersiones = ReglaProceso::create([
        'nombre' => PREFIJO.'Con versiones',
        'tipo_proceso_id' => $otroTipo->id,
        'plan_id' => $oferta->plan_id,
    ]);

    $v1 = versionDe($reglaVersiones, ['vigente_desde' => now()->subYears(2)->toDateString(), 'horas_requeridas' => 500]);
    $v2 = versionDe($reglaVersiones, ['vigente_desde' => now()->subMonth()->toDateString(), 'horas_requeridas' => 480]);
    $futura = versionDe($reglaVersiones, ['vigente_desde' => now()->addMonth()->toDateString(), 'horas_requeridas' => 400]);

    verificar('Hoy rige la última que ya entró en vigor',
        $resolutor->versionVigente($reglaVersiones)?->id === $v2->id);

    verificar('Y una futura NO rige todavía',
        $resolutor->versionVigente($reglaVersiones)?->id !== $futura->id);

    verificar('A una fecha pasada rige la que regía entonces',
        $resolutor->versionVigente($reglaVersiones, now()->subYear()->toDateString())?->id === $v1->id);

    /*
     * Y la pantalla dice CUÁL rige, preguntándoselo al resolutor. Deducirlo de
     * las fechas en el navegador sería una segunda definición: con tres
     * versiones publicadas, dos pueden estar «en vigor» y sólo una manda.
     */
    $detalle = props($controlador, 'show', $global, [], [$reglaVersiones]);

    $vigentes = collect($detalle['versiones'])->where('es_la_vigente', true);

    verificar('El detalle marca UNA sola versión como la que rige', $vigentes->count() === 1);

    verificar('Y es la que el resolutor contesta',
        $vigentes->first()['id'] === $v2->id);

    verificar('La futura sale como que todavía no está en vigor',
        collect($detalle['versiones'])->firstWhere('id', $futura->id)['en_vigor'] === false);

    $sinVersiones = ReglaProceso::create([
        'nombre' => PREFIJO.'Sin versiones',
        'tipo_proceso_id' => $otroTipo->id,
        'campus_id' => $oferta->campus_id,
        'programa_academico_id' => $oferta->programa_academico_id,
        'plan_id' => $oferta->plan_id,
        'modalidad' => $oferta->modalidad,
    ]);

    verificar('Una regla sin versiones no tiene versión vigente',
        $resolutor->versionVigente($sinVersiones) === null);

    echo PHP_EOL.'5. La elegibilidad falla CERRADO'.PHP_EOL;

    $sinNada = TipoProcesoFormativo::query()->where('clave', 'internado')->firstOrFail();

    $dictamen = $elegibilidad->para($matricula, $sinNada);

    verificar('Sin regla configurada NO es elegible', ! $dictamen['elegible']);

    verificar('Y el motivo lo dice con esas palabras',
        str_contains($dictamen['impedimentos'][0] ?? '', 'no tiene configurado'),
        $dictamen['impedimentos'][0] ?? '');

    // La regla más específica del otro tipo es la que NO tiene versiones.
    $dictamenSinVersion = $elegibilidad->para($matricula, $otroTipo);

    verificar('Con regla pero sin requisitos publicados, tampoco',
        ! $dictamenSinVersion['elegible']
        && str_contains($dictamenSinVersion['impedimentos'][0] ?? '', 'todavía no tiene requisitos'),
        $dictamenSinVersion['impedimentos'][0] ?? '');

    echo PHP_EOL.'6. Devuelve la LISTA de lo que falta, no un sí o un no'.PHP_EOL;

    $version = $resolutor->versionVigente($delPlan);

    // Se le exige de todo, y de todo lo que no puede cumplir.
    $situacionAjena = SituacionAlumno::query()->whereKeyNot($matricula->situacion_id)->firstOrFail();

    $version->update([
        'porcentaje_creditos_minimo' => 99.99,
        'periodo_minimo' => 9,
        'solicitud_desde' => now()->addMonth()->toDateString(),
        'solicitud_hasta' => now()->addMonths(2)->toDateString(),
    ]);
    $version->situacionesPermitidas()->create(['situacion_alumno_id' => $situacionAjena->id]);
    $version->refresh()->load('situacionesPermitidas.situacion');

    $dictamen = $elegibilidad->para($matricula, $tipo);

    verificar('No es elegible', ! $dictamen['elegible']);

    /*
     * TODOS los motivos a la vez. De uno en uno, alguien arreglaría los
     * créditos, reintentaría y se enteraría del periodo — y después de la
     * situación, y después de la ventana.
     */
    verificar('Y salen los CUATRO motivos juntos',
        count($dictamen['impedimentos']) === 4,
        implode(' | ', $dictamen['impedimentos']));

    verificar('Cada uno dice el número concreto',
        collect($dictamen['impedimentos'])->contains(fn ($m) => str_contains($m, '99.99'))
        && collect($dictamen['impedimentos'])->contains(fn ($m) => str_contains($m, 'periodo 5'))
        && collect($dictamen['impedimentos'])->contains(fn ($m) => str_contains($m, 'ventana')),
        implode(' | ', $dictamen['impedimentos']));

    echo PHP_EOL.'7. Y también lo que YA cumple'.PHP_EOL;

    $version->update([
        'porcentaje_creditos_minimo' => 0.01,
        'periodo_minimo' => 1,
        'solicitud_desde' => null,
        'solicitud_hasta' => null,
    ]);
    $version->situacionesPermitidas()->delete();
    $version->refresh()->load('situacionesPermitidas');

    $dictamen = $elegibilidad->para($matricula, $tipo);

    verificar('Ahora sí es elegible', $dictamen['elegible'], implode(' | ', $dictamen['impedimentos']));

    verificar('Y se enseña lo cumplido, no sólo un «sí»',
        count($dictamen['cumplidos']) >= 2, implode(' | ', $dictamen['cumplidos']));

    verificar('El avance de créditos viene con sus dos cifras',
        $dictamen['avance']['porcentaje_creditos'] !== null
        && $dictamen['avance']['creditos_del_plan'] > 0);

    /*
     * Y sin periodo capturado NO se da por cumplido. Es el caso normal en una
     * escuela recién migrada —en el demo, las 32 matrículas tienen la columna
     * vacía—, así que darlo por bueno dejaría pasar a todo el mundo por el
     * requisito que más se usa. Se culpa al DATO, no al alumno.
     */
    $matricula->forceFill(['periodo_actual' => null])->save();
    $version->update(['periodo_minimo' => 3]);
    $version->refresh();

    $sinPeriodo = $elegibilidad->para($matricula->refresh(), $tipo);

    verificar('Sin periodo capturado NO se da por cumplido',
        ! $sinPeriodo['elegible']
        && collect($sinPeriodo['impedimentos'])->contains(fn ($m) => str_contains($m, 'No tienes capturado el periodo')),
        implode(' | ', $sinPeriodo['impedimentos']));

    $matricula->forceFill(['periodo_actual' => 5])->save();
    $version->update(['periodo_minimo' => 1]);
    $version->refresh();
    $matricula->refresh()->load('oferta.plan', 'oferta.programaAcademico', 'situacion');

    echo PHP_EOL.'8. Las materias previas se miden por APROBADAS'.PHP_EOL;

    /*
     * Una materia del plan que el alumno NO tiene aprobada. Se busca entre las
     * que no están en su historial aprobado: si se tomara cualquiera, podría
     * caer una que ya lleva y la comprobación pasaría por la razón equivocada.
     */
    $aprobadas = DB::table('historial')
        ->where('matricula_oferta_id', $matricula->id)
        ->whereNull('deleted_at')
        ->pluck('plan_materia_id')
        ->filter()
        ->all();

    $pendiente = PlanMateria::query()
        ->where('plan_id', $oferta->plan_id)
        ->whereNotIn('id', $aprobadas ?: [0])
        ->with('asignatura:id,nombre')
        ->first();

    verificar('Hay una materia del plan que no lleva aprobada', $pendiente !== null);

    $version->materiasPrevias()->create(['plan_materia_id' => $pendiente->id]);
    $version->refresh()->load('materiasPrevias.planMateria.asignatura');

    $dictamen = $elegibilidad->para($matricula, $tipo);

    verificar('Exigirla lo vuelve no elegible', ! $dictamen['elegible']);

    verificar('Y el motivo la NOMBRA',
        collect($dictamen['impedimentos'])->contains(
            fn ($m) => str_contains($m, $pendiente->asignatura?->nombre ?? 'materia'),
        ),
        implode(' | ', $dictamen['impedimentos']));

    /*
     * Y el caso que separa CURSADA de APROBADA: una materia que el alumno SÍ
     * tiene en su historial, pero reprobada. Sin ella, contar las cursadas
     * daría el mismo resultado que contar las aprobadas —la materia pendiente
     * no está en el historial de ninguna forma— y la regla quedaría sin
     * comprobar.
     */
    $reprobado = DB::table('estatus_historial')->where('clave', 'reprobada')->first();

    verificar('Hay un estatus «reprobada» con el que construir el caso', $reprobado !== null);

    $unaAprobada = Historial::query()
        ->where('matricula_oferta_id', $matricula->id)
        ->aprobadas()
        ->whereNotNull('plan_materia_id')
        ->firstOrFail();

    $estatusAprobada = $unaAprobada->estatus_id;
    $unaAprobada->forceFill(['estatus_id' => $reprobado->id])->save();

    // Se parte de una lista vacía: con la materia pendiente de la sección
    // anterior todavía puesta, «deja de faltar» no podría cumplirse nunca.
    $version->materiasPrevias()->delete();
    $version->materiasPrevias()->create(['plan_materia_id' => $unaAprobada->plan_materia_id]);
    $version->refresh()->load('materiasPrevias.planMateria.asignatura');

    $conReprobada = $elegibilidad->para($matricula, $tipo);

    verificar('Una materia CURSADA y reprobada no cuenta como aprobada',
        ! $conReprobada['elegible']
        && collect($conReprobada['impedimentos'])->contains(fn ($m) => str_contains($m, 'Te faltan por aprobar')),
        implode(' | ', $conReprobada['impedimentos']));

    verificar('Y al aprobarla, deja de faltar',
        (function () use ($unaAprobada, $estatusAprobada, $version, $elegibilidad, $matricula, $tipo) {
            $unaAprobada->forceFill(['estatus_id' => $estatusAprobada])->save();
            $version->refresh()->load('materiasPrevias.planMateria.asignatura');

            return $elegibilidad->para($matricula, $tipo)['elegible'];
        })());

    $version->materiasPrevias()->delete();
    $version->refresh()->load('materiasPrevias');

    echo PHP_EOL.'8b. El no adeudo va por el MISMO camino que la inscripción'.PHP_EOL;

    $version->update(['exige_no_adeudo' => true]);
    $version->refresh();

    verificar('Sin adeudo bloqueante, es elegible y se dice',
        $elegibilidad->para($matricula, $tipo)['elegible']
        && collect($elegibilidad->para($matricula, $tipo)['cumplidos'])
            ->contains(fn ($c) => str_contains($c, 'adeudos')));

    /*
     * La situación financiera se escribe en su bitácora, que es lo que
     * `ValidadorInscripcion` consulta. Preguntarle a `adeudos` por nuestra
     * cuenta daría una segunda verdad sobre lo que alguien debe.
     */
    $bloqueante = SituacionPago::query()->where('bloquea', true)->firstOrFail();

    DB::table('bitacora_situacion_financiera')->insert([
        'matricula_oferta_id' => $matricula->id,
        'situacion_id' => $bloqueante->id,
        'momento' => now()->subDay(),
        'motivo' => PREFIJO.'para la prueba',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $dictamenAdeudo = $elegibilidad->para($matricula, $tipo);

    verificar('Con la situación que BLOQUEA, no es elegible', ! $dictamenAdeudo['elegible']);

    verificar('Y el motivo nombra su situación financiera',
        collect($dictamenAdeudo['impedimentos'])->contains(
            fn ($m) => str_contains($m, $bloqueante->nombre),
        ),
        implode(' | ', $dictamenAdeudo['impedimentos']));

    // Y con la regla que NO lo exige, la misma deuda no estorba.
    $version->update(['exige_no_adeudo' => false]);
    $version->refresh();

    verificar('Sin exigirlo, la misma deuda no estorba',
        $elegibilidad->para($matricula, $tipo)['elegible']);

    echo PHP_EOL.'9. Las guardas de una regla que no alcanzaría a nadie'.PHP_EOL;

    $rechaza = function (array $datos) use ($controlador, $global): bool {
        try {
            $controlador->guardar(peticionCon($datos, $global));

            return false;
        } catch (AvisoParaElUsuario $e) {
            return $e->getStatusCode() === 422;
        }
    };

    verificar('El rango de generaciones al revés',
        $rechaza([
            'nombre' => PREFIJO.'Al revés',
            'tipo_proceso_id' => $tipo->id,
            'generacion_desde' => '2024',
            'generacion_hasta' => '2020',
        ]));

    verificar('Un plan que no es del programa declarado',
        $rechaza([
            'nombre' => PREFIJO.'Plan ajeno',
            'tipo_proceso_id' => $tipo->id,
            'programa_academico_id' => $otroPrograma->id,
            'plan_id' => $oferta->plan_id,
        ]));

    $rechazaVersion = function (array $datos) use ($controlador, $global, $general): bool {
        try {
            $controlador->crearVersion(peticionCon(array_merge([
                'vigente_desde' => now()->toDateString(),
            ], $datos), $global), $general);

            return false;
        } catch (AvisoParaElUsuario $e) {
            return $e->getStatusCode() === 422;
        }
    };

    verificar('Una tolerancia que se traga las horas',
        $rechazaVersion(['horas_requeridas' => 480, 'tolerancia_horas' => 480]));

    verificar('Informes parciales sin decir cada cuánto',
        $rechazaVersion(['informes_parciales' => 3]));

    verificar('Y la ventana de solicitud al revés',
        (function () use ($controlador, $global, $general) {
            try {
                $controlador->crearVersion(peticionCon([
                    'vigente_desde' => now()->toDateString(),
                    'solicitud_desde' => now()->addMonth()->toDateString(),
                    'solicitud_hasta' => now()->toDateString(),
                ], $global), $general);

                return false;
            } catch (ValidationException) {
                return true;
            }
        })());

    echo PHP_EOL.'10. Las listas de una versión son SUYAS'.PHP_EOL;

    $otraVersion = versionDe($general);

    verificar('Una versión de otra regla da 404',
        (function () use ($controlador, $global, $delPlan, $otraVersion) {
            try {
                $controlador->agregarSituacion(
                    peticionCon(['situacion_alumno_id' => 1], $global),
                    $delPlan,
                    $otraVersion,
                );

                return false;
            } catch (AvisoParaElUsuario $e) {
                return $e->getStatusCode() === 404;
            }
        })());

    verificar('Una materia de otro plan se rehúsa CON su motivo',
        (function () use ($controlador, $global, $general, $otraVersion, $pendiente) {
            try {
                $controlador->agregarMateria(
                    peticionCon(['plan_materia_id' => $pendiente->id], $global),
                    $general,
                    $otraVersion,
                );

                return false;
            } catch (AvisoParaElUsuario $e) {
                return $e->getStatusCode() === 422 && str_contains($e->getMessage(), 'sin poder cumplirla');
            }
        })());

    /*
     * Una lista inventada en la URL da 404 — y el caso se construye con un
     * renglón que SÍ existe, para que un `default` mal escrito tenga algo que
     * borrar. Con un id inexistente el 404 sale igual por el camino de «ese
     * renglón no es de esta versión», y la guarda quedaría sin comprobar.
     */
    $renglonReal = $otraVersion->situacionesPermitidas()->create([
        'situacion_alumno_id' => $matricula->situacion_id,
    ]);

    verificar('Y una lista inventada en la URL da 404',
        (function () use ($controlador, $general, $otraVersion, $renglonReal) {
            try {
                $controlador->quitarRenglon($general, $otraVersion, 'inventada', $renglonReal->id);

                return false;
            } catch (AvisoParaElUsuario $e) {
                return $e->getStatusCode() === 404;
            }
        })());

    verificar('Y no se llevó por delante el renglón',
        $otraVersion->situacionesPermitidas()->whereKey($renglonReal->id)->exists());

    echo PHP_EOL.'11. La tolerancia se resta en UN solo sitio'.PHP_EOL;

    $conTolerancia = versionDe($general, ['horas_requeridas' => 480, 'tolerancia_horas' => 5]);

    verificar('Las horas mínimas descuentan la tolerancia',
        $conTolerancia->horasMinimas() === 475, (string) $conTolerancia->horasMinimas());

    $sinHoras = versionDe($general);

    verificar('Sin horas exigidas no hay mínimo, y NO es cero',
        $sinHoras->horasMinimas() === null);

    echo PHP_EOL.'12. El portal del alumno'.PHP_EOL;

    $alumno = usuarioConRol('alumno', (int) $matricula->persona_id);

    $vistas = props($portal, 'index', $alumno);

    verificar('Salen sus matrículas', count($vistas['matriculas']) >= 1);

    verificar('Y un dictamen por cada tipo encendido',
        count($vistas['procesos']) === TipoProcesoFormativo::query()->activos()->count(),
        count($vistas['procesos']).' de '.TipoProcesoFormativo::query()->activos()->count());

    $servicioSocial = collect($vistas['procesos'])->firstWhere('tipo_id', $tipo->id);

    verificar('El del servicio social dice qué regla se le aplicó',
        ($servicioSocial['regla']['nombre'] ?? null) === PREFIJO.'Del plan',
        (string) ($servicioSocial['regla']['nombre'] ?? 'ninguna'));

    /*
     * Pedir la matrícula de OTRO no da error ni la enseña: cae a la propia.
     * Un 403 confirmaría que ese id existe.
     */
    $ajena = MatriculaOferta::query()->where('persona_id', '!=', $matricula->persona_id)->firstOrFail();

    $conAjena = props($portal, 'index', $alumno, ['matricula' => $ajena->id]);

    verificar('Pedir la matrícula de otro cae en la propia, sin 403',
        collect($vistas['matriculas'])->pluck('id')->contains($conAjena['elegida']));

    echo PHP_EOL.'13. Se ENTRA con un permiso y se TOCA con otro'.PHP_EOL;

    $mirón = usuarioConRol('administrativo');

    verificar('Quien no configura, no puede', ! $mirón->can('configurar-procesos-formativos'));

    verificar('Y la pantalla se lo dice',
        props($controlador, 'index', $mirón)['puedeEditar'] === false
        && props($controlador, 'index', $global)['puedeEditar'] === true);

} catch (Throwable $falla) {
    $verificaciones++;
    $fallidas++;
    echo "  \033[31mFALLA\033[39m la suite murió antes de terminar: ".$falla->getMessage()
        .' ('.basename($falla->getFile()).':'.$falla->getLine().')'.PHP_EOL;
} finally {
    $db->rollBack();

    echo PHP_EOL.'Resultado: '.($verificaciones - $fallidas)." correctas, {$fallidas} fallidas".PHP_EOL;
}
