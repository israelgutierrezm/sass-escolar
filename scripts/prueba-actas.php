<?php

/**
 * Prueba de integración del asentamiento de actas, contra la base REAL del
 * tenant demo. Todo dentro de una transacción con rollback al final.
 *
 * Se corre con `php scripts/prueba-actas.php` desde la raíz del proyecto.
 *
 * No vive en tests/ a propósito: phpunit está configurado contra SQLite en
 * memoria y aquí se prueba justamente lo que SQLite no sabe hacer — el
 * incremento atómico con LAST_INSERT_ID de MySQL, las FKs reales y el
 * comportamiento del motor InnoDB bajo transacción.
 */

$raiz = dirname(__DIR__);

require $raiz.'/vendor/autoload.php';
$app = require $raiz.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Academico\EsquemaEvaluacion;
use App\Models\Academico\Oferta;
use App\Models\Academico\PlanMateria;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\Admisiones\SituacionAlumno;
use App\Models\ControlEscolar\Acta;
use App\Models\ControlEscolar\AsignaturaGrupo;
use App\Models\ControlEscolar\CalificacionComponente;
use App\Models\ControlEscolar\Historial;
use App\Models\ControlEscolar\Inscripcion;
use App\Models\ControlEscolar\SituacionInscripcion;
use App\Models\Identidad\Persona;
use App\Services\AsentadorActa;
use App\Services\CalculadoraCalificacion;
use App\Services\GeneradorFolioActa;
use Illuminate\Support\Facades\DB;

tenancy()->initialize(App\Models\Tenant::find('demo'));

$ok = 0;
$fallos = [];

function verificar(string $titulo, bool $condicion, string $detalle = ''): void
{
    global $ok, $fallos;

    if ($condicion) {
        $ok++;
        echo "  OK   {$titulo}".($detalle !== '' ? "  [{$detalle}]" : '').PHP_EOL;
    } else {
        $fallos[] = $titulo;
        echo "  FALLA {$titulo}".($detalle !== '' ? "  [{$detalle}]" : '').PHP_EOL;
    }
}

DB::beginTransaction();

try {
    $asentador = app(AsentadorActa::class);
    $calculadora = app(CalculadoraCalificacion::class);

    // ---------------------------------------------------------------
    // Preparación
    // ---------------------------------------------------------------
    $materiaGrupo = AsignaturaGrupo::with('grupo.ciclo', 'planMateria.plan')->firstOrFail();
    $planMateria = $materiaGrupo->planMateria;
    $plan = $planMateria->plan;
    $ciclo = $materiaGrupo->grupo->ciclo;
    $oferta = Oferta::firstOrFail();

    $plan->update([
        'calificacion_minima' => 0,
        'calificacion_maxima' => 10,
        'calificacion_minima_aprobatoria' => 6,
    ]);
    $plan->refresh();

    // Ventana de captura abierta, para no disparar "extemporánea" aún.
    $ciclo->update(['captura_calif_hasta' => now()->addMonth()->toDateString()]);

    // Esquema: 30 / 30 / 40. Se limpian primero las capturas que lo referencian
    // (la FK las protege) y las actas previas de la materia.
    $componentesViejos = EsquemaEvaluacion::where('plan_materia_id', $planMateria->id)->pluck('id');
    Historial::withTrashed()->whereNotNull('acta_id')->forceDelete();
    Acta::withTrashed()->whereNotNull('acta_origen_id')->forceDelete();
    Acta::withTrashed()->forceDelete();
    CalificacionComponente::withTrashed()->whereIn('esquema_evaluacion_id', $componentesViejos)->forceDelete();
    EsquemaEvaluacion::where('plan_materia_id', $planMateria->id)->forceDelete();
    $p1 = EsquemaEvaluacion::create(['plan_materia_id' => $planMateria->id, 'componente' => 'parcial_1', 'parcial' => 1, 'porcentaje' => 30, 'orden' => 1]);
    $p2 = EsquemaEvaluacion::create(['plan_materia_id' => $planMateria->id, 'componente' => 'parcial_2', 'parcial' => 2, 'porcentaje' => 30, 'orden' => 2]);
    $pf = EsquemaEvaluacion::create(['plan_materia_id' => $planMateria->id, 'componente' => 'final', 'parcial' => null, 'porcentaje' => 40, 'orden' => 3]);

    $situacionAlumno = SituacionAlumno::firstOrFail();
    $inscrito = SituacionInscripcion::where('clave', 'inscrito')->firstOrFail();
    $baja = SituacionInscripcion::where('clave', 'baja')->firstOrFail();

    // Cuatro alumnos: aprobado, reprobado, recursador y uno de baja.
    $sufijo = substr((string) microtime(true), -6);
    $alumnos = [];

    foreach (['Aprobado', 'Reprobado', 'Recursador', 'Bajado'] as $i => $etiqueta) {
        $persona = Persona::create([
            'nombre' => 'Prueba',
            'primer_apellido' => $etiqueta,
            'sexo_id' => 1,
        ]);

        $matricula = MatriculaOferta::create([
            'persona_id' => $persona->id,
            'oferta_id' => $oferta->id,
            'matricula' => "TEST{$sufijo}{$i}",
            'fecha_ingreso' => now()->toDateString(),
            'situacion_id' => $situacionAlumno->id,
            'estatus' => 'activo',
        ]);

        $alumnos[$etiqueta] = Inscripcion::create([
            'matricula_oferta_id' => $matricula->id,
            'asignatura_grupo_id' => $materiaGrupo->id,
            'ciclo_id' => $ciclo->id,
            'tipo' => $etiqueta === 'Recursador' ? Inscripcion::TIPO_RECURSAMIENTO : Inscripcion::TIPO_ORDINARIA,
            'forma_inscripcion' => Inscripcion::FORMA_ADMINISTRATIVA,
            'situacion_id' => $etiqueta === 'Bajado' ? $baja->id : $inscrito->id,
        ]);
    }

    echo PHP_EOL."1. Alcance del acta".PHP_EOL;

    $calificables = $asentador->inscripcionesCalificables($materiaGrupo);
    $idsCalificables = $calificables->pluck('id')->all();

    verificar(
        'El alumno dado de baja no entra al acta',
        ! in_array($alumnos['Bajado']->id, $idsCalificables, true),
        count($idsCalificables).' calificables'
    );
    verificar(
        'Los tres activos sí entran',
        count(array_intersect(
            [$alumnos['Aprobado']->id, $alumnos['Reprobado']->id, $alumnos['Recursador']->id],
            $idsCalificables
        )) === 3
    );

    echo PHP_EOL.'2. Cálculo de la calificación final'.PHP_EOL;

    // Captura parcial: solo el primer parcial del alumno aprobado.
    CalificacionComponente::create([
        'inscripcion_id' => $alumnos['Aprobado']->id,
        'esquema_evaluacion_id' => $p1->id,
        'calificacion' => 10,
    ]);

    $esquema = $asentador->esquema($materiaGrupo);
    $parcial = $calculadora->calcular($alumnos['Aprobado']->fresh('calificaciones'), $esquema, $plan);

    verificar('Con captura incompleta no se dictamina aprobado', $parcial->aprobada === null);
    verificar('Se reportan los componentes faltantes', $parcial->faltantes === ['parcial_2', 'final'], implode(',', $parcial->faltantes));
    verificar('Un 10 en un componente de 30% NO se infla a 10', abs(($parcial->final ?? 0) - 3.0) < 0.001, (string) $parcial->final);

    // Captura completa de los tres.
    $notas = [
        'Aprobado' => [$p1->id => 10, $p2->id => 8, $pf->id => 9],   // 3 + 2.4 + 3.6 = 9.0
        'Reprobado' => [$p1->id => 5, $p2->id => 4, $pf->id => 3],   // 1.5 + 1.2 + 1.2 = 3.9
        'Recursador' => [$p1->id => 6, $p2->id => 6, $pf->id => 6],  // 6.0
    ];

    foreach ($notas as $etiqueta => $valores) {
        foreach ($valores as $componenteId => $valor) {
            CalificacionComponente::updateOrCreate(
                ['inscripcion_id' => $alumnos[$etiqueta]->id, 'esquema_evaluacion_id' => $componenteId],
                ['calificacion' => $valor],
            );
        }
    }

    $resultados = [];
    foreach (['Aprobado', 'Reprobado', 'Recursador'] as $etiqueta) {
        $resultados[$etiqueta] = $calculadora->calcular($alumnos[$etiqueta]->fresh('calificaciones'), $esquema, $plan);
    }

    verificar('Ponderación 30/30/40 correcta (10,8,9 → 9.00)', abs($resultados['Aprobado']->final - 9.0) < 0.001, (string) $resultados['Aprobado']->final);
    verificar('Ponderación correcta (5,4,3 → 3.90)', abs($resultados['Reprobado']->final - 3.9) < 0.001, (string) $resultados['Reprobado']->final);
    verificar('Aprobado con 9.00 y mínima 6', $resultados['Aprobado']->aprobada === true);
    verificar('Reprobado con 3.90 y mínima 6', $resultados['Reprobado']->aprobada === false);
    verificar('Justo en la mínima (6.00) cuenta como aprobado', $resultados['Recursador']->aprobada === true, (string) $resultados['Recursador']->final);

    echo PHP_EOL.'3. El esquema manda'.PHP_EOL;

    $pf->update(['porcentaje' => 30]); // ahora suma 90
    $malEsquema = $calculadora->calcular($alumnos['Aprobado']->fresh('calificaciones'), $asentador->esquema($materiaGrupo->fresh()), $plan);
    verificar('Un esquema que no suma 100 no calcula', $malEsquema->final === null && $malEsquema->motivo !== null, (string) $malEsquema->motivo);
    $pf->update(['porcentaje' => 40]);

    echo PHP_EOL.'4. Impedimentos para cerrar'.PHP_EOL;

    $docente = Persona::first();

    // La materia ya tenía inscritos antes de esta prueba: se les captura un 8
    // parejo para que el único faltante sea el que la prueba provoca.
    foreach ($asentador->inscripcionesCalificables($materiaGrupo) as $otra) {
        if (in_array($otra->id, array_map(fn ($i) => $i->id, $alumnos), true)) {
            continue;
        }

        foreach ([$p1, $p2, $pf] as $componente) {
            CalificacionComponente::updateOrCreate(
                ['inscripcion_id' => $otra->id, 'esquema_evaluacion_id' => $componente->id],
                ['calificacion' => 8],
            );
        }
    }

    // Borro un componente para dejar a un alumno incompleto.
    CalificacionComponente::where('inscripcion_id', $alumnos['Reprobado']->id)
        ->where('esquema_evaluacion_id', $pf->id)->forceDelete();

    $acta = $asentador->actaDeTrabajo($materiaGrupo->fresh(['planMateria.esquemaEvaluacion', 'planMateria.plan']));
    $impedimentos = $asentador->impedimentos($acta);

    verificar('Falta de captura impide cerrar', count($impedimentos) === 1, implode(' | ', $impedimentos));
    verificar('El impedimento nombra al alumno y el componente',
        str_contains($impedimentos[0] ?? '', 'Reprobado') && str_contains($impedimentos[0] ?? '', 'final'));

    $cerroConFaltantes = false;
    try {
        $asentador->cerrar($acta, $docente->id);
    } catch (RuntimeException) {
        $cerroConFaltantes = true;
    }
    verificar('cerrar() rechaza un acta incompleta', $cerroConFaltantes);

    // Se completa la captura.
    CalificacionComponente::create([
        'inscripcion_id' => $alumnos['Reprobado']->id,
        'esquema_evaluacion_id' => $pf->id,
        'calificacion' => 3,
    ]);

    verificar('Sin faltantes, ya no hay impedimentos', $asentador->impedimentos($acta->fresh()) === []);

    echo PHP_EOL.'5. Cierre del acta y volcado al kárdex'.PHP_EOL;

    $acta = $asentador->cerrar($acta->fresh(), $docente->id);

    verificar('El acta queda cerrada', $acta->situacion === Acta::CERRADA);
    verificar('Se emitió folio real (no borrador)', ! str_starts_with($acta->folio, 'BORRADOR'), $acta->folio);
    verificar('Queda registrado quién firmó y cuándo', $acta->cerrada_por === $docente->id && $acta->cerrada_en !== null);

    $esperados = $asentador->inscripcionesCalificables($materiaGrupo)->count();
    $renglones = Historial::where('acta_id', $acta->id)->get();
    verificar('Un renglón por alumno calificable, ni uno más',
        $renglones->count() === $esperados, $renglones->count()." de {$esperados}");
    verificar('Todos llevan el folio del acta', $renglones->every(fn ($h) => $h->acta_folio === $acta->folio));

    $porMatricula = $renglones->keyBy(fn ($h) => $h->matriculaOferta->matricula);
    $mAprobado = $alumnos['Aprobado']->matriculaOferta->matricula;
    $mReprobado = $alumnos['Reprobado']->matriculaOferta->matricula;
    $mRecursador = $alumnos['Recursador']->matriculaOferta->matricula;

    verificar('El aprobado entra al kárdex como aprobada',
        $porMatricula[$mAprobado]->estatus->clave === 'aprobada' && (float) $porMatricula[$mAprobado]->calificacion === 9.0);
    verificar('El reprobado entra como reprobada',
        $porMatricula[$mReprobado]->estatus->clave === 'reprobada' && (float) $porMatricula[$mReprobado]->calificacion === 3.9);
    verificar('El recursamiento se asienta con tipo_evaluacion recursamiento',
        $porMatricula[$mRecursador]->tipoEvaluacion->clave === 'recursamiento',
        $porMatricula[$mRecursador]->tipoEvaluacion->clave);
    verificar('Los ordinarios se asientan como ordinaria',
        $porMatricula[$mAprobado]->tipoEvaluacion->clave === 'ordinaria');

    $alumnos['Aprobado']->refresh();
    verificar('La final se escribió en inscripcion.calificacion_final',
        (float) $alumnos['Aprobado']->calificacion_final === 9.0, (string) $alumnos['Aprobado']->calificacion_final);

    verificar('El alumno de baja no tiene renglón',
        Historial::where('acta_id', $acta->id)
            ->where('matricula_oferta_id', $alumnos['Bajado']->matricula_oferta_id)->doesntExist());

    echo PHP_EOL.'5b. Una materia se asienta una sola vez'.PHP_EOL;

    // Regresión: antes se podía cerrar una SEGUNDA acta ordinaria sobre la
    // misma materia, duplicando los renglones del kárdex sin que nadie lo
    // notara. El alumno aparecía dos veces en la misma materia.
    $segunda = $asentador->actaDeTrabajo($materiaGrupo);
    $impedimentosSegunda = $asentador->impedimentos($segunda);

    verificar('Un segundo cierre ordinario está impedido',
        count($impedimentosSegunda) === 1 && str_contains($impedimentosSegunda[0], 'ya tiene acta asentada'),
        implode(' | ', $impedimentosSegunda));

    $seDuplico = true;
    try {
        $asentador->cerrar($segunda, $docente->id);
    } catch (RuntimeException) {
        $seDuplico = false;
    }
    verificar('cerrar() rechaza el segundo asentamiento', ! $seDuplico);
    // Se cuentan solo los renglones nacidos de un acta: la materia puede
    // traer historial previo cargado por otra vía (revalidación, semilla).
    $conActa = Historial::where('asignatura_grupo_id', $materiaGrupo->id)->whereNotNull('acta_id')->count();
    verificar('El kárdex no se duplicó', $conActa === $esperados, $conActa." de {$esperados}");

    $segunda->forceDelete(); // no dejar el borrador estorbando a la corrección

    echo PHP_EOL.'6. Acta de corrección'.PHP_EOL;

    $correccion = $asentador->abrirCorreccion($acta, 'Se capturó mal el examen final del alumno reprobado.');
    verificar('La corrección apunta al acta original', $correccion->acta_origen_id === $acta->id);
    verificar('La corrección nace abierta', $correccion->situacion === Acta::ABIERTA);
    verificar('Pedir corrección dos veces reutiliza la abierta',
        $asentador->abrirCorreccion($acta, 'otro motivo')->id === $correccion->id);

    // Se corrige la calificación del reprobado: pasa a 9.
    CalificacionComponente::where('inscripcion_id', $alumnos['Reprobado']->id)
        ->where('esquema_evaluacion_id', $pf->id)
        ->update(['calificacion' => 9]);

    $correccion = $asentador->cerrar($correccion->fresh(), $docente->id);

    verificar('El acta de corrección tiene folio propio y distinto',
        $correccion->folio !== $acta->folio && ! str_starts_with($correccion->folio, 'BORRADOR'), $correccion->folio);
    verificar('Los renglones del acta original quedaron dados de baja',
        Historial::where('acta_id', $acta->id)->count() === 0);
    verificar('Pero se conservan con soft delete (trazabilidad)',
        Historial::withTrashed()->where('acta_id', $acta->id)->count() === $esperados);

    $corregido = Historial::where('acta_id', $correccion->id)
        ->where('matricula_oferta_id', $alumnos['Reprobado']->matricula_oferta_id)->first();

    verificar('La corrección asienta la calificación nueva',
        (float) $corregido->calificacion === 6.3 && $corregido->estatus->clave === 'aprobada',
        (string) $corregido->calificacion);
    verificar('La corrección se marca en observaciones del kárdex',
        $corregido->observacion?->clave === 'correccion_calificacion', (string) $corregido->observacion?->clave);
    verificar('El acta original sigue existiendo y cerrada',
        $acta->fresh()->situacion === Acta::CERRADA);

    echo PHP_EOL.'7. Acta extemporánea'.PHP_EOL;

    $ciclo->update(['captura_calif_hasta' => now()->subDay()->toDateString()]);
    $materiaGrupo2 = AsignaturaGrupo::with('grupo.ciclo')->where('id', '!=', $materiaGrupo->id)->first();

    if ($materiaGrupo2 !== null) {
        // Reusa el mismo plan_materia para tener esquema; si no lo comparte, se salta.
        $extemporanea = $asentador->actaDeTrabajo($materiaGrupo);
        // El acta se abre sobre la misma materia; con la ventana vencida el
        // volcado debe marcarse extemporáneo.
        $reflexion = new ReflectionMethod(AsentadorActa::class, 'observacionDelActa');
        $reflexion->setAccessible(true);
        $obsId = $reflexion->invoke($asentador, $extemporanea, $materiaGrupo->fresh('grupo.ciclo'));
        $obs = App\Models\ControlEscolar\ObservacionHistorial::find($obsId);

        verificar('Fuera de la ventana de captura se marca como extemporánea',
            $obs?->clave === 'acta_extemporanea', (string) $obs?->clave);
    }

    echo PHP_EOL.'8. Folio atómico'.PHP_EOL;

    $folios = [];
    $generador = app(GeneradorFolioActa::class);
    for ($i = 0; $i < 200; $i++) {
        $folios[] = $generador->generar($materiaGrupo);
    }

    verificar('200 folios generados, 200 distintos',
        count(array_unique($folios)) === 200,
        count(array_unique($folios)).' únicos');
    verificar('El folio respeta el formato configurado',
        (bool) preg_match('/^ACT-\d{4}-\d{5}$/', $folios[0]), $folios[0]);

    echo PHP_EOL.'9. La captura no se reabre sola'.PHP_EOL;

    verificar('actaDeTrabajo no devuelve un acta ya cerrada',
        $asentador->actaDeTrabajo($materiaGrupo)->situacion === Acta::ABIERTA);
    verificar('No se puede corregir un acta que no está cerrada',
        (function () use ($asentador, $materiaGrupo) {
            try {
                $asentador->abrirCorreccion($asentador->actaDeTrabajo($materiaGrupo), 'x');

                return false;
            } catch (RuntimeException) {
                return true;
            }
        })());
} catch (Throwable $e) {
    echo PHP_EOL.'EXCEPCIÓN: '.$e->getMessage().PHP_EOL;
    echo $e->getFile().':'.$e->getLine().PHP_EOL;
    $fallos[] = 'excepción: '.$e->getMessage();
} finally {
    DB::rollBack();
    echo PHP_EOL.'-- rollback aplicado, la base queda como estaba --'.PHP_EOL;
}

echo PHP_EOL."Resultado: {$ok} correctas, ".count($fallos).' fallidas'.PHP_EOL;

foreach ($fallos as $fallo) {
    echo "  - {$fallo}".PHP_EOL;
}

exit($fallos === [] ? 0 : 1);
