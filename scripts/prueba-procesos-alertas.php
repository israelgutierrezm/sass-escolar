<?php

/**
 * Alertas y reportes del módulo formativo (fase 7). Con rollback.
 *
 * Se corre con `php scripts/prueba-procesos-alertas.php` desde la raíz.
 *
 * ── Qué se vigila, y por qué ──────────────────────────────────────────────
 *  1. **El RASTRO impide el goteo.** Sin él, el comando diario volvería a
 *     avisar cada mañana mientras la condición siga siendo cierta — y un
 *     recordatorio que llega treinta días seguidos deja de leerse al tercero.
 *  2. **Y NO basta un `SELECT` previo**: el único de la base es la defensa,
 *     porque dos corridas simultáneas lo pasan las dos.
 *  3. **El modo SECO no escribe nada.** Existe para poder encenderlo con calma;
 *     si dejara rastro, la primera prueba en seco mataría el aviso de verdad.
 *  4. **No se avisa de lo que no toca**: un informe entregado no vence, y un
 *     expediente concluido no recibe recordatorios de plazo.
 *  5. **El aviso de «listo para liberar» es para la ESCUELA**, no para el
 *     alumno: él ya hizo lo suyo y liberar no está en sus manos.
 *  6. **Las alertas NO escriben en el expediente**: reportan y ya.
 *  7. **Las tres fuentes de reportes** devuelven filas, con su recorte por
 *     campus y sus totales bien declarados.
 */

use App\Models\Academico\Campus;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\Identidad\Persona;
use App\Models\Identidad\Rol;
use App\Models\Identidad\Usuario;
use App\Models\Plataforma\Aviso;
use App\Models\ProcesosFormativos\AlertaProceso;
use App\Models\ProcesosFormativos\ConvenioFormativo;
use App\Models\ProcesosFormativos\EstadoExpediente;
use App\Models\ProcesosFormativos\EvaluacionProceso;
use App\Models\ProcesosFormativos\ExpedienteProceso;
use App\Models\ProcesosFormativos\OrganizacionReceptora;
use App\Models\ProcesosFormativos\ReglaProceso;
use App\Models\ProcesosFormativos\SituacionConvenioFormativo;
use App\Models\ProcesosFormativos\SituacionOrganizacion;
use App\Models\ProcesosFormativos\TipoProcesoFormativo;
use App\Models\Tenant;
use App\Reportes\Ejecutor;
use App\Reportes\RegistroReportes;
use App\Services\ProcesosFormativos\AlertasFormativas;
use App\Services\ProcesosFormativos\AsignadorDePlaza;
use App\Services\ProcesosFormativos\InformesYEvaluaciones;
use App\Services\ProcesosFormativos\RegistradorDeHoras;
use App\Services\ProcesosFormativos\TransicionDeExpediente;
use Carbon\CarbonImmutable;
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

function usuarioConRol(string $rol, ?int $personaId = null): Usuario
{
    $personaId ??= Persona::create([
        'nombre' => 'Prueba',
        'primer_apellido' => 'Alertas',
        'segundo_apellido' => (string) random_int(1000, 9999),
        'sexo_id' => 1,
    ])->id;

    $rolId = Rol::where('name', $rol)->firstOrFail()->id;

    $cuenta = Usuario::query()->where('persona_id', $personaId)->first();

    $cuenta
        ? $cuenta->forceFill(['rol_activo_id' => $rolId])->save()
        : $cuenta = Usuario::create([
            'persona_id' => $personaId,
            'usuario' => 'prueba_alr_'.random_int(100000, 999999),
            'email' => 'prueba_alr_'.random_int(100000, 999999).'@ejemplo.mx',
            'password' => Hash::make('secreto12345'),
            'rol_activo_id' => $rolId,
        ]);

    $cuenta->persona->asignacionesRol()->firstOrCreate(
        ['rol_id' => $rolId],
        ['activo' => true, 'campus_id' => null],
    );

    return $cuenta->fresh(['persona', 'rolActivo']);
}

const PREFIJO = 'ZZALR-';

$db->beginTransaction();

try {
    $alertas = app(AlertasFormativas::class);
    $transiciones = app(TransicionDeExpediente::class);
    $asignador = app(AsignadorDePlaza::class);
    $horas = app(RegistradorDeHoras::class);
    $papeleo = app(InformesYEvaluaciones::class);
    $registro = app(RegistroReportes::class);
    $ejecutor = app(Ejecutor::class);

    $global = usuarioConRol('director_general');
    auth()->login($global);

    /*
     * Se parte de CERO: lo que se prueba es CUÁNTAS alertas se levantan y sobre
     * quién, y eso sólo se puede afirmar sabiendo qué hay. Octava vez que este
     * proyecto se cobra lo mismo.
     */
    DB::table('alertas_proceso')->delete();
    DB::table('liberaciones_proceso')->update(['liberacion_corregida_id' => null]);
    DB::table('liberaciones_proceso')->delete();
    DB::table('expedientes_proceso')->delete();
    DB::table('reglas_proceso')->delete();
    DB::table('convenios_formativos')->delete();

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
        'horas_requeridas' => 16,
        'tolerancia_horas' => 0,
        'informes_parciales' => 1,
        'periodicidad_informe_dias' => 30,
        'exige_informe_final' => true,
        'exige_evaluacion_supervisor' => true,
    ]);

    $organizacion = OrganizacionReceptora::create([
        'razon_social' => PREFIJO.'Receptora',
        'situacion_id' => SituacionOrganizacion::query()->where('acepta_asignaciones', true)->firstOrFail()->id,
    ]);

    /*
     * El expediente se coloca en el PASADO: su primer informe ya venció y su
     * periodo también. Sin eso no habría nada que avisar y la suite mediría
     * «cero alertas» creyendo que prueba algo.
     */
    $inicio = CarbonImmutable::now()->subMonths(4)->startOfDay();

    $expediente = $transiciones->abrir([
        'matricula_oferta_id' => $matricula->id,
        'tipo_proceso_id' => $tipo->id,
        'regla_version_id' => $version->id,
        'horas_requeridas' => 16,
    ], $global);

    foreach ([EstadoExpediente::Solicitado, EstadoExpediente::EnRevision, EstadoExpediente::Aprobado] as $paso) {
        $expediente = $transiciones->mover($expediente, $paso, $global);
    }

    $expediente = $asignador->asignar($expediente, [
        'organizacion_id' => $organizacion->id,
        'fecha_inicio' => $inicio->toDateString(),
        'fecha_fin_programada' => $inicio->addMonths(2)->toDateString(),
    ], $global);

    $expediente = $transiciones->mover($expediente, EstadoExpediente::EnCurso, $global);

    echo PHP_EOL.'1. Lo que hay que avisar hoy'.PHP_EOL;

    $hoy = CarbonImmutable::now()->startOfDay();

    $pendientes = $alertas->deEsteExpediente($expediente->refresh()->load('informes.tipo', 'tipoProceso'), $hoy);

    $eventos = collect($pendientes)->pluck('evento')->all();

    verificar('El informe vencido se avisa',
        in_array(AlertaProceso::INFORME_VENCIDO, $eventos, true), implode(', ', $eventos));

    verificar('Y el plazo vencido también',
        in_array(AlertaProceso::PLAZO_VENCIDO, $eventos, true), implode(', ', $eventos));

    verificar('Nada de «listo para liberar»: el expediente está en curso',
        ! in_array(AlertaProceso::LISTO_PARA_LIBERAR, $eventos, true));

    echo PHP_EOL.'2. El modo SECO no escribe nada'.PHP_EOL;

    $enSeco = $alertas->correr($hoy, seco: true);

    verificar('En seco dice a quién le llegaría', count($enSeco) >= 2, (string) count($enSeco));

    verificar('Y NO deja rastro: la primera prueba no puede matar el aviso de verdad',
        AlertaProceso::query()->count() === 0);

    verificar('Ni levanta un solo aviso',
        Aviso::query()->where('titulo', 'like', '%'.PREFIJO.'%')->count() === 0);

    echo PHP_EOL.'3. La corrida de verdad levanta los avisos'.PHP_EOL;

    $antesDeAvisos = Aviso::query()->count();

    $primera = $alertas->correr($hoy);

    verificar('Se levantó lo mismo que anunciaba el seco',
        count($primera) === count($enSeco), count($primera).' contra '.count($enSeco));

    verificar('Cada uno dejó su rastro',
        AlertaProceso::query()->count() === count($primera),
        AlertaProceso::query()->count().' rastros para '.count($primera).' alertas');

    verificar('Y cada rastro APUNTA a su aviso',
        AlertaProceso::query()->whereNull('aviso_id')->count() === 0);

    verificar('Se crearon los avisos',
        Aviso::query()->count() === $antesDeAvisos + count($primera));

    $delInforme = AlertaProceso::query()->where('evento', AlertaProceso::INFORME_VENCIDO)->firstOrFail();

    verificar('El aviso del informe le llega AL ALUMNO',
        $delInforme->aviso?->destinos()->where('destino_id', $matricula->persona_id)->exists() === true);

    verificar('Y caduca: pasado el plazo diría algo que quizá ya no es cierto',
        $delInforme->aviso?->vigente_hasta !== null);

    /*
     * La hora REAL y no la medianoche. Con `startOfDay` el aviso sale fechado
     * «12:00 a.m.» y se lee como si la escuela trabajara de madrugada — el
     * defecto que se vio en el portal con los recordatorios de cobranza.
     */
    verificar('Con la hora real del envío, no la medianoche',
        $delInforme->aviso?->publicado_desde?->format('H:i') !== '00:00',
        (string) $delInforme->aviso?->publicado_desde?->format('H:i'));

    echo PHP_EOL.'4. El RASTRO impide el goteo'.PHP_EOL;

    $rastrosAntes = AlertaProceso::query()->count();
    $avisosAntes = Aviso::query()->count();

    $segunda = $alertas->correr($hoy);

    verificar('La segunda corrida no levanta nada', $segunda === [], (string) count($segunda));

    verificar('Ni deja rastros nuevos', AlertaProceso::query()->count() === $rastrosAntes);

    verificar('Ni avisos nuevos', Aviso::query()->count() === $avisosAntes);

    /*
     * Y BORRAR el aviso no resucita la alerta: el rastro sobrevive con
     * `aviso_id` en null. Sin eso, borrar un aviso haría que el comando volviera
     * a avisar a la mañana siguiente.
     */
    $delInforme->aviso?->forceDelete();

    verificar('El rastro sobrevive al borrado del aviso',
        AlertaProceso::query()->whereKey($delInforme->id)->exists());

    verificar('Y aun así no se vuelve a avisar', $alertas->correr($hoy) === []);

    echo PHP_EOL.'5. Un informe ENTREGADO deja de vencer'.PHP_EOL;

    // Se limpia el rastro para poder volver a medir: lo que se prueba ahora es
    // la CONDICIÓN, no la idempotencia.
    AlertaProceso::query()->forceDelete();

    $vencido = $expediente->informes()->whereNotNull('fecha_limite')->orderBy('numero')->firstOrFail();

    $papeleo->entregar($vencido, 'ruta/x.pdf', 'x.pdf');

    $eventosAhora = collect($alertas->deEsteExpediente($expediente->refresh()->load('informes.tipo'), $hoy))
        ->map(fn ($a) => $a['evento'].':'.$a['referencia'])
        ->all();

    verificar('El entregado ya no aparece',
        ! in_array(AlertaProceso::INFORME_VENCIDO.':'.$vencido->id, $eventosAhora, true),
        implode(', ', $eventosAhora));

    echo PHP_EOL.'6. El aviso de «listo para liberar» es para la ESCUELA'.PHP_EOL;

    // Se completa todo para que el expediente quede liberable.
    $expediente->forceFill(['estado' => EstadoExpediente::EnCurso->value])->save();

    foreach ([0, 1] as $dia) {
        $horas->aprobar($horas->capturar($expediente->refresh(), [
            'fecha' => $inicio->addDays($dia)->toDateString(),
            'hora_inicio' => '09:00',
            'hora_fin' => '17:00',
            'actividad' => 'Jornada del día '.$dia.'.',
        ], $global), $global);
    }

    foreach ($expediente->refresh()->informes as $informe) {
        $informe->entregado_en === null && $papeleo->entregar($informe, 'ruta/i.pdf', 'i.pdf');
        $papeleo->revisar($informe->refresh(), true, null, $global);
    }

    $papeleo->evaluar($expediente->refresh(), EvaluacionProceso::SUPERVISOR, null, [], 'Bien.', $global);

    $expediente = $transiciones->mover($expediente->refresh(), EstadoExpediente::Concluido, $global);

    AlertaProceso::query()->forceDelete();

    $deConcluido = $alertas->deEsteExpediente($expediente->refresh()->load('informes.tipo', 'evaluaciones'), $hoy);

    $listo = collect($deConcluido)->firstWhere('evento', AlertaProceso::LISTO_PARA_LIBERAR);

    verificar('Un concluido que ya cumple todo se avisa', $listo !== null,
        implode(', ', collect($deConcluido)->pluck('evento')->all()));

    verificar('Y va dirigido a la ESCUELA, no al alumno',
        $listo['para_la_escuela'] === true);

    verificar('Un CONCLUIDO ya no recibe avisos de plazo: no está trabajando',
        ! collect($deConcluido)->pluck('evento')->contains(AlertaProceso::PLAZO_VENCIDO),
        implode(', ', collect($deConcluido)->pluck('evento')->all()));

    /*
     * Y uno que concluyó SIN cumplir no se avisa, que es la mitad que faltaba:
     * con todos los concluidos del escenario ya liberables, quitarle al servicio
     * su comprobación de `sePuedeLiberar` no cambiaba ningún resultado. Se
     * construye el caso — sin una sola hora aprobada de las dieciséis que pide
     * su regla.
     */
    /*
     * Con OTRA matricula: el unico `expediente_vivo_unico` de la fase 4 impide
     * dos expedientes vivos del mismo tipo para la misma, que es justo lo que
     * esa regla existe para impedir.
     */
    $otraMatricula = MatriculaOferta::query()
        ->whereKeyNot($matricula->id)
        ->whereHas('oferta', fn ($o) => $o->where('plan_id', $matricula->oferta->plan_id))
        ->firstOrFail();

    $aMedias = $transiciones->abrir([
        'matricula_oferta_id' => $otraMatricula->id,
        'tipo_proceso_id' => $tipo->id,
        'regla_version_id' => $version->id,
        'horas_requeridas' => 16,
    ], $global);

    $aMedias->forceFill(['estado' => EstadoExpediente::Concluido->value])->save();

    $delMedias = collect($alertas->deEsteExpediente($aMedias->refresh()->load('informes.tipo'), $hoy))
        ->pluck('evento')->all();

    verificar('Un concluido al que le faltan horas NO se anuncia como liberable',
        ! in_array(AlertaProceso::LISTO_PARA_LIBERAR, $delMedias, true), implode(', ', $delMedias));

    // Y se retira para no estorbar a las secciones siguientes, que cuentan.
    $aMedias->forceDelete();

    $alertas->correr($hoy);

    $rastroListo = AlertaProceso::query()->where('evento', AlertaProceso::LISTO_PARA_LIBERAR)->firstOrFail();

    /*
     * Le llega POR ROL y no a una persona: el responsable puede cambiar, irse de
     * vacaciones o dejar la escuela, y un aviso dirigido a él se quedaría sin
     * leer. El rol es lo que sobrevive.
     */
    verificar('El aviso va por ROL, no a una persona concreta',
        $rastroListo->aviso?->destinos()->where('tipo', 'rol')->exists() === true);

    verificar('Y a un rol que de verdad puede liberar',
        Rol::query()
            ->whereIn('id', $rastroListo->aviso->destinos()->pluck('destino_id'))
            ->get()
            ->every(fn ($rol) => $rol->concede('liberar-expedientes-formativos')));

    echo PHP_EOL.'7. Las alertas NO escriben en el expediente'.PHP_EOL;

    $comoQuedo = $expediente->refresh();

    verificar('El estado no se movió',
        $comoQuedo->estado === EstadoExpediente::Concluido);

    verificar('Ni las fechas ni las horas',
        (int) $comoQuedo->horas_aprobadas === 16
        && $comoQuedo->fecha_fin_programada?->toDateString() === $inicio->addMonths(2)->toDateString());

    echo PHP_EOL.'8. La ventana de aviso PREVIO'.PHP_EOL;

    /*
     * Un informe que vence DENTRO de la ventana se avisa; uno que vence más
     * lejos, no. El caso se construye moviendo la fecha: sin él, «se avisa con
     * antelación» y «se avisa siempre» darían el mismo resultado.
     */
    $expediente->forceFill(['estado' => EstadoExpediente::EnCurso->value])->save();

    $unInforme = $expediente->informes()->first();
    $unInforme->forceFill([
        'entregado_en' => null,
        'estado' => 'pendiente',
        'fecha_limite' => $hoy->addDays(3)->toDateString(),
    ])->save();

    $conVentana = collect($alertas->deEsteExpediente($expediente->refresh()->load('informes.tipo'), $hoy))
        ->pluck('evento')->all();

    verificar('Dentro de la ventana se avisa con antelación',
        in_array(AlertaProceso::INFORME_POR_VENCER, $conVentana, true), implode(', ', $conVentana));

    $unInforme->forceFill(['fecha_limite' => $hoy->addDays(40)->toDateString()])->save();

    $lejos = collect($alertas->deEsteExpediente($expediente->refresh()->load('informes.tipo'), $hoy))
        ->pluck('evento')->all();

    verificar('Y fuera de ella, NO',
        ! in_array(AlertaProceso::INFORME_POR_VENCER, $lejos, true), implode(', ', $lejos));

    /*
     * Y lo MISMO para el plazo, que hasta aquí sólo se probaba VENCIDO —y esa
     * rama sale antes de llegar a la ventana, así que quitarle su condición no
     * cambiaba nada—. El caso se construye: un periodo que termina dentro de
     * cien días no se avisa, y uno que termina dentro de diez, sí.
     */
    $expediente->forceFill(['fecha_fin_programada' => $hoy->addDays(100)->toDateString()])->save();

    $plazoLejos = collect($alertas->deEsteExpediente($expediente->refresh()->load('informes.tipo'), $hoy))
        ->pluck('evento')->all();

    verificar('Un periodo que termina dentro de cien días no se avisa',
        ! in_array(AlertaProceso::PLAZO_POR_VENCER, $plazoLejos, true)
        && ! in_array(AlertaProceso::PLAZO_VENCIDO, $plazoLejos, true),
        implode(', ', $plazoLejos));

    $expediente->forceFill(['fecha_fin_programada' => $hoy->addDays(10)->toDateString()])->save();

    $plazoCerca = collect($alertas->deEsteExpediente($expediente->refresh()->load('informes.tipo'), $hoy))
        ->pluck('evento')->all();

    verificar('Y uno que termina dentro de diez, sí',
        in_array(AlertaProceso::PLAZO_POR_VENCER, $plazoCerca, true), implode(', ', $plazoCerca));

    echo PHP_EOL.'9. Las tres fuentes de reportes existen y devuelven filas'.PHP_EOL;

    $expediente->forceFill(['estado' => EstadoExpediente::EnCurso->value])->save();

    // Un convenio, para que la tercera fuente tenga qué contar.
    ConvenioFormativo::create([
        'organizacion_id' => $organizacion->id,
        'folio' => PREFIJO.'C-1',
        'situacion_id' => SituacionConvenioFormativo::query()->where('ampara_asignaciones', true)->firstOrFail()->id,
        'vigente_desde' => now()->subMonth()->toDateString(),
        'vigente_hasta' => now()->addDays(30)->toDateString(),
    ]);

    foreach (['expedientes_formativos', 'horas_formativas', 'convenios_formativos'] as $clave) {
        verificar('La fuente «'.$clave.'» está registrada',
            $registro->fuenteONull($clave) !== null);
    }

    $corre = fn (string $reporte) => $ejecutor->ejecutar($global, $reporte, ['por_pagina' => 200]);

    verificar('«Servicio social en curso» trae al expediente',
        collect($corre('procesos-en-curso')->filas)
            ->contains(fn ($f) => ($f['matricula'] ?? null) === $matricula->matricula),
        (string) count($corre('procesos-en-curso')->filas));

    verificar('«Horas acreditadas» trae sus jornadas',
        count($corre('horas-acreditadas')->filas) >= 2,
        (string) count($corre('horas-acreditadas')->filas));

    verificar('«Convenios vigentes» trae el convenio',
        collect($corre('convenios-formativos-vigentes')->filas)
            ->contains(fn ($f) => ($f['folio'] ?? null) === PREFIJO.'C-1'));

    verificar('Y «Convenios por vencer» también: vence en 30 días',
        collect($corre('convenios-formativos-por-vencer')->filas)
            ->contains(fn ($f) => ($f['folio'] ?? null) === PREFIJO.'C-1'));

    echo PHP_EOL.'10. Los totales están DECLARADOS uno por uno'.PHP_EOL;

    /*
     * Una columna numérica sin decir qué va al pie no deja arrancar la
     * aplicación —lo vigila `ColumnaReporte`—, así que esto comprueba lo otro:
     * que lo declarado sea lo correcto. Las horas SUMAN; los umbrales, los
     * ordinales y los porcentajes NO.
     */
    $expedientes = $registro->fuente('expedientes_formativos');

    verificar('Las horas hechas SUMAN',
        $expedientes->columnas()['horas_aprobadas']->total === App\Reportes\Agregacion::Suma);

    verificar('Las horas PEDIDAS no: son un umbral repetido por fila',
        $expedientes->columnas()['horas_requeridas']->total === App\Reportes\Agregacion::Ninguno);

    verificar('Ni el avance: promediar porcentajes de procesos distintos no dice nada',
        $expedientes->columnas()['avance']->total === App\Reportes\Agregacion::Ninguno);

    verificar('En la bitácora, las horas SUMAN',
        $registro->fuente('horas_formativas')->columnas()['horas']->total === App\Reportes\Agregacion::Suma);

    verificar('Y la versión del convenio NO: es un ordinal',
        $registro->fuente('convenios_formativos')->columnas()['version']->total === App\Reportes\Agregacion::Ninguno);

    echo PHP_EOL.'11. El RECORTE por campus'.PHP_EOL;

    $otroCampus = Campus::query()->whereKeyNot($matricula->oferta->campus_id)->firstOrFail();

    $acotado = usuarioConRol('director_general');
    $acotado->persona->asignacionesRol()->update(['campus_id' => $otroCampus->id]);
    $acotado = $acotado->fresh(['persona.asignacionesRol', 'rolActivo']);

    $delAcotado = $ejecutor->ejecutar($acotado, 'procesos-en-curso', ['por_pagina' => 200]);

    verificar('El acotado a OTRO campus no ve el expediente',
        collect($delAcotado->filas)->doesntContain(fn ($f) => ($f['matricula'] ?? null) === $matricula->matricula));

    verificar('Y el global sí',
        collect($corre('procesos-en-curso')->filas)
            ->contains(fn ($f) => ($f['matricula'] ?? null) === $matricula->matricula));

    verificar('La bitácora también se acota',
        collect($ejecutor->ejecutar($acotado, 'horas-acreditadas', ['por_pagina' => 200])->filas)
            ->doesntContain(fn ($f) => ($f['matricula'] ?? null) === $matricula->matricula));

    /*
     * Y los CONVENIOS no se acotan: `sinCampus()` LANZA 403 a un rol acotado. Es
     * la decisión escrita —un convenio lo firma la dirección con una
     * organización que no pertenece a ningún plantel—, y esto la fija.
     */
    verificar('Los convenios le dan 403 a un rol acotado, con su razón',
        (function () use ($ejecutor, $registro, $acotado) {
            try {
                $ejecutor->ejecutar($acotado, 'convenios-formativos-vigentes', ['por_pagina' => 200]);

                return false;
            } catch (Throwable $e) {
                return str_contains($e->getMessage(), 'no pertenece a ningún');
            }
        })());

    echo PHP_EOL.'12. El folio del reporte es el VIGENTE'.PHP_EOL;

    /*
     * Un expediente cuya constancia se corrigió tiene DOS liberaciones, y el
     * reporte tiene que citar la que vale. El caso se construye: sin él,
     * filtrar las corregidas y no filtrarlas dan lo mismo.
     */
    $liberador = app(App\Services\ProcesosFormativos\LiberadorDeExpediente::class);

    foreach ($expediente->refresh()->informes as $informe) {
        $informe->entregado_en === null && $papeleo->entregar($informe, 'ruta/i2.pdf', 'i2.pdf');
        $informe->estado === 'aceptado' || $papeleo->revisar($informe->refresh(), true, null, $global);
    }

    $expediente = $transiciones->mover($expediente->refresh(), EstadoExpediente::Concluido, $global);

    $primeraLib = $liberador->liberar($expediente, $global);
    $correccion = $liberador->corregir($primeraLib, 'Se capturó mal la organización.', $global);

    $fila = collect($corre('procesos-liberados')->filas)
        ->firstWhere('matricula', $matricula->matricula);

    verificar('Sale el folio de la liberación VIGENTE',
        ($fila['folio_liberacion'] ?? null) === $correccion->folio,
        ($fila['folio_liberacion'] ?? 'null').' contra '.$correccion->folio);

    verificar('Y NO el de la corregida',
        ($fila['folio_liberacion'] ?? null) !== $primeraLib->folio);

} catch (Throwable $falla) {
    $verificaciones++;
    $fallidas++;
    echo "  \033[31mFALLA\033[39m la suite murió antes de terminar: ".$falla->getMessage()
        .' ('.basename($falla->getFile()).':'.$falla->getLine().')'.PHP_EOL;
} finally {
    $db->rollBack();

    echo PHP_EOL.'Resultado: '.($verificaciones - $fallidas)." correctas, {$fallidas} fallidas".PHP_EOL;
}
