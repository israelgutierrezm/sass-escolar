<?php

/**
 * Prueba de integración de las plantillas de evaluación y el reparto
 * equitativo, contra la base REAL del tenant demo. Todo con rollback.
 *
 * Se corre con `php scripts/prueba-plantillas.php` desde la raíz.
 */

$raiz = dirname(__DIR__);

require $raiz.'/vendor/autoload.php';
$app = require $raiz.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Academico\Asignatura;
use App\Models\Academico\EsquemaEvaluacion;
use App\Models\Academico\PlanEstudio;
use App\Models\Academico\PlanMateria;
use App\Models\Academico\PlantillaComponente;
use App\Models\Academico\PlantillaEvaluacion;
use App\Models\ControlEscolar\CalificacionComponente;
use App\Models\ControlEscolar\Inscripcion;
use App\Services\AplicadorPlantillaEvaluacion;
use App\Services\RepartidorPorcentajes;
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

echo '1. Reparto equitativo (sin tocar la base)'.PHP_EOL;

$repartidor = new RepartidorPorcentajes();

foreach ([1, 2, 3, 4, 6, 7, 9, 11] as $n) {
    $reparto = $repartidor->equitativo($n);
    $suma = round(array_sum($reparto), 2);
    $spread = round(max($reparto) - min($reparto), 2);

    verificar(
        "{$n} rubros suman exactamente 100",
        $suma === 100.0 && $spread <= 0.01,
        implode(' + ', array_map(fn ($p) => number_format($p, 2), $reparto))." = {$suma}"
    );
}

DB::beginTransaction();

try {
    $aplicador = app(AplicadorPlantillaEvaluacion::class);

    $sufijo = substr((string) microtime(true), -6);
    $plan = PlanEstudio::firstOrFail();
    $asignatura = Asignatura::firstOrFail();

    // Tres materias nuevas para no depender del estado del plan.
    $materias = [];
    foreach (['A', 'B', 'C'] as $letra) {
        $materias[$letra] = PlanMateria::create([
            'plan_id' => $plan->id,
            'asignatura_id' => $asignatura->id,
            'clave_en_plan' => "T{$sufijo}{$letra}",
            'periodo' => 1,
            'tipo' => 'obligatoria',
        ]);
    }

    // La materia C se arma a mano: no debe ser tocada por la plantilla.
    EsquemaEvaluacion::create([
        'plan_materia_id' => $materias['C']->id,
        'componente' => 'proyecto_unico', 'parcial' => null, 'porcentaje' => 100, 'orden' => 1,
    ]);

    $plantilla = PlantillaEvaluacion::create([
        'clave' => "prueba_{$sufijo}",
        'nombre' => 'Plantilla de prueba',
        'activa' => true,
    ]);

    foreach ([
        ['asistencia', 1, 10], ['examen_p1', 1, 15],
        ['asistencia_2', 2, 10], ['examen_p2', 2, 15],
        ['final', 3, 30], ['participacion', 3, 20],
    ] as $orden => [$nombre, $parcial, $pct]) {
        PlantillaComponente::create([
            'plantilla_id' => $plantilla->id,
            'componente' => $nombre, 'parcial' => $parcial, 'porcentaje' => $pct, 'orden' => $orden + 1,
        ]);
    }

    echo PHP_EOL.'2. La plantilla como criterio'.PHP_EOL;

    $plantilla->refresh()->load('componentes');
    verificar('Suma 100% y está completa', $plantilla->estaCompleta(), $plantilla->sumaPorcentajes().'%');
    verificar('Detecta 3 parciales', $plantilla->numeroDeParciales() === 3);

    $incompleta = PlantillaEvaluacion::create(['clave' => "inc_{$sufijo}", 'nombre' => 'Incompleta', 'activa' => true]);
    PlantillaComponente::create(['plantilla_id' => $incompleta->id, 'componente' => 'x', 'parcial' => null, 'porcentaje' => 60, 'orden' => 1]);

    $rechazada = false;
    try {
        $aplicador->aplicarAMateria($incompleta->refresh(), $materias['A']);
    } catch (RuntimeException) {
        $rechazada = true;
    }
    verificar('Una plantilla que no suma 100 no se puede aplicar', $rechazada);

    echo PHP_EOL.'3. Aplicar al plan completo'.PHP_EOL;

    $resultado = $aplicador->aplicarAPlan($plantilla, $plan, respetarPersonalizadas: true);

    $materias['A']->refresh();
    $componentesA = EsquemaEvaluacion::where('plan_materia_id', $materias['A']->id)->get();

    verificar('La materia recibe los 6 rubros', $componentesA->count() === 6, $componentesA->count().' rubros');
    verificar('Los porcentajes se copian tal cual',
        abs((float) $componentesA->sum('porcentaje') - 100.0) < 0.01);
    verificar('Se conserva a qué parcial pertenece cada rubro',
        $componentesA->whereNotNull('parcial')->pluck('parcial')->unique()->sort()->values()->all() === [1, 2, 3]);
    verificar('La materia queda ligada a la plantilla',
        $materias['A']->plantilla_evaluacion_id === $plantilla->id);
    verificar('El plan la fija como criterio por defecto',
        $plan->fresh()->plantilla_evaluacion_id === $plantilla->id);

    $materias['C']->refresh();
    verificar('La materia con esquema propio NO se pisa',
        $materias['C']->plantilla_evaluacion_id === null
        && EsquemaEvaluacion::where('plan_materia_id', $materias['C']->id)->count() === 1,
        'omitidas: '.$resultado['omitidas']);

    echo PHP_EOL.'4. Editar la plantilla cambia todas'.PHP_EOL;

    $plantilla->componentes()->where('componente', 'participacion')->update(['porcentaje' => 10]);
    $plantilla->componentes()->where('componente', 'final')->update(['porcentaje' => 40]);

    $aplicador->repropagar($plantilla->refresh()->load('componentes'));

    $finalA = EsquemaEvaluacion::where('plan_materia_id', $materias['A']->id)
        ->where('componente', 'final')->first();

    verificar('El cambio llega a las materias que la siguen',
        (float) $finalA->porcentaje === 40.0, (string) $finalA->porcentaje);
    verificar('…y siguen sumando 100',
        abs((float) EsquemaEvaluacion::where('plan_materia_id', $materias['A']->id)->sum('porcentaje') - 100.0) < 0.01);

    echo PHP_EOL.'5. Lo capturado nunca se pisa'.PHP_EOL;

    // Se captura una calificación contra un rubro de la materia B.
    $inscripcion = Inscripcion::first();
    $rubroB = EsquemaEvaluacion::where('plan_materia_id', $materias['B']->id)->first();

    CalificacionComponente::create([
        'inscripcion_id' => $inscripcion->id,
        'esquema_evaluacion_id' => $rubroB->id,
        'calificacion' => 8,
    ]);

    $bloqueadas = $aplicador->materiasBloqueadas($plantilla);
    verificar('La materia con capturas se reporta como bloqueada',
        count($bloqueadas) === 1 && str_contains($bloqueadas[0], "T{$sufijo}B"), implode(' | ', $bloqueadas));

    $seBloqueo = false;
    try {
        $aplicador->aplicarAMateria($plantilla, $materias['B']);
    } catch (RuntimeException) {
        $seBloqueo = true;
    }
    verificar('Aplicar a una materia con capturas se rechaza', $seBloqueo);

    $idRubroB = $rubroB->id;
    $resultado = $aplicador->repropagar($plantilla);

    verificar('La re-propagación la salta en vez de fallar',
        count($resultado['bloqueadas']) === 1 && $resultado['aplicadas'] >= 1,
        'aplicadas: '.$resultado['aplicadas'].', bloqueadas: '.count($resultado['bloqueadas']));
    verificar('El rubro capturado sigue existiendo (no quedó huérfano)',
        EsquemaEvaluacion::whereKey($idRubroB)->exists());
    verificar('Y la calificación capturada sigue ahí',
        CalificacionComponente::where('esquema_evaluacion_id', $idRubroB)->exists());

    echo PHP_EOL.'6. Reparto equitativo sobre una plantilla real'.PHP_EOL;

    $tres = PlantillaEvaluacion::create(['clave' => "eq_{$sufijo}", 'nombre' => 'Equitativa', 'activa' => true]);
    foreach (['asistencia', 'examen', 'actividades'] as $orden => $nombre) {
        PlantillaComponente::create([
            'plantilla_id' => $tres->id, 'componente' => $nombre,
            'parcial' => null, 'porcentaje' => 0, 'orden' => $orden + 1,
        ]);
    }

    $aplicador->repartirEquitativo($tres->refresh());
    $tres->refresh()->load('componentes');

    verificar('Tres rubros quedan en 33.34 / 33.33 / 33.33',
        $tres->estaCompleta(),
        $tres->componentes->pluck('porcentaje')->implode(' + ').' = '.$tres->sumaPorcentajes());
    verificar('Y por tanto ya se puede aplicar', $tres->estaCompleta());
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
