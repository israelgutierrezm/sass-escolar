<?php

/**
 * Prueba de integración del calendario de captura: ventanas por parcial y
 * excepciones. Contra la base REAL del tenant demo, con rollback.
 *
 * Se corre con `php scripts/prueba-ventanas-captura.php` desde la raíz.
 */

$raiz = dirname(__DIR__);

require $raiz.'/vendor/autoload.php';
$app = require $raiz.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Academico\EsquemaEvaluacion;
use App\Models\ControlEscolar\AsignaturaGrupo;
use App\Models\ControlEscolar\CalificacionComponente;
use App\Models\ControlEscolar\ExcepcionCaptura;
use App\Models\ControlEscolar\VentanaCaptura;
use App\Models\Identidad\Persona;
use App\Services\CalendarioCaptura;
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
    $calendario = app(CalendarioCaptura::class);

    $materiaGrupo = AsignaturaGrupo::with('grupo.ciclo', 'planMateria')->firstOrFail();
    $ciclo = $materiaGrupo->grupo->ciclo;
    $planMateria = $materiaGrupo->planMateria;

    // Esquema con dos parciales y un rubro directo al curso. Se limpian primero
    // las capturas que lo referencian: la FK las protege.
    CalificacionComponente::withTrashed()->whereIn(
        'esquema_evaluacion_id',
        EsquemaEvaluacion::where('plan_materia_id', $planMateria->id)->select('id')
    )->forceDelete();
    EsquemaEvaluacion::where('plan_materia_id', $planMateria->id)->forceDelete();
    foreach ([
        ['examen_p1', 1, 30], ['examen_p2', 2, 30], ['asistencia', null, 40],
    ] as $orden => [$nombre, $parcial, $pct]) {
        EsquemaEvaluacion::create([
            'plan_materia_id' => $planMateria->id,
            'componente' => $nombre, 'parcial' => $parcial, 'porcentaje' => $pct, 'orden' => $orden + 1,
        ]);
    }
    $materiaGrupo->unsetRelation('planMateria');

    echo PHP_EOL.'1. Sin ventanas, la captura es libre'.PHP_EOL;

    VentanaCaptura::where('ciclo_id', $ciclo->id)->forceDelete();
    $estado = $calendario->estadoPorParcial($materiaGrupo);

    verificar('Los tres cortes salen abiertos',
        count($estado) === 3 && collect($estado)->every(fn ($e) => $e['abierto']),
        implode(', ', array_keys($estado)));
    verificar('No hay motivos de cierre', $calendario->cerrados($materiaGrupo) === []);

    echo PHP_EOL.'2. Una ventana vigente abre; una vencida cierra'.PHP_EOL;

    $abierta = VentanaCaptura::create([
        'ciclo_id' => $ciclo->id, 'parcial' => 1, 'nombre' => 'Primer parcial',
        'desde' => now()->subDays(5)->toDateString(),
        'hasta' => now()->addDays(5)->toDateString(),
        'activa' => true,
    ]);

    $vencida = VentanaCaptura::create([
        'ciclo_id' => $ciclo->id, 'parcial' => 2, 'nombre' => 'Segundo parcial',
        'desde' => now()->subDays(30)->toDateString(),
        'hasta' => now()->subDays(10)->toDateString(),
        'activa' => true,
    ]);

    $estado = $calendario->estadoPorParcial($materiaGrupo);

    verificar('El parcial 1 (vigente) está abierto', $estado['1']['abierto']);
    verificar('El parcial 2 (vencido) está cerrado', ! $estado['2']['abierto']);
    verificar('…y explica desde cuándo',
        str_contains($estado['2']['motivo'] ?? '', 'cerró el'), (string) $estado['2']['motivo']);

    verificar('El corte SIN ventana propia queda abierto',
        $estado['']['abierto'], 'la escuela configuró unas y no otras');

    echo PHP_EOL.'3. Desactivar cierra sin borrar'.PHP_EOL;

    $abierta->update(['activa' => false]);
    $estado = $calendario->estadoPorParcial($materiaGrupo);

    verificar('Desactivada, el parcial 1 se cierra', ! $estado['1']['abierto']);
    verificar('…y lo dice como desactivación, no como fecha',
        str_contains($estado['1']['motivo'] ?? '', 'desactivada'), (string) $estado['1']['motivo']);

    $abierta->update(['activa' => true]);
    verificar('Reactivada, vuelve a abrir',
        $calendario->estadoPorParcial($materiaGrupo)['1']['abierto']);

    echo PHP_EOL.'4. Ventana futura'.PHP_EOL;

    $abierta->update([
        'desde' => now()->addDays(5)->toDateString(),
        'hasta' => now()->addDays(20)->toDateString(),
    ]);

    $estado = $calendario->estadoPorParcial($materiaGrupo);
    verificar('Antes de su apertura está cerrada', ! $estado['1']['abierto']);
    verificar('…y dice cuándo abre',
        str_contains($estado['1']['motivo'] ?? '', 'abre el'), (string) $estado['1']['motivo']);

    $abierta->update([
        'desde' => now()->subDays(5)->toDateString(),
        'hasta' => now()->addDays(5)->toDateString(),
    ]);

    echo PHP_EOL.'5. Excepciones'.PHP_EOL;

    $docente = Persona::firstOrFail();
    $otro = Persona::where('id', '!=', $docente->id)->firstOrFail();

    $excepcion = ExcepcionCaptura::create([
        'ventana_id' => $vencida->id,
        'asignatura_grupo_id' => $materiaGrupo->id,
        'persona_id' => $docente->id,
        'hasta' => now()->addDays(3)->toDateString(),
        'motivo' => 'El docente estuvo incapacitado durante la ventana.',
        'autorizada_por' => $otro->id,
    ]);

    $conExcepcion = $calendario->estadoPorParcial($materiaGrupo, $docente->id);
    $sinExcepcion = $calendario->estadoPorParcial($materiaGrupo, $otro->id);

    verificar('Al docente con excepción se le reabre el parcial 2',
        $conExcepcion['2']['abierto'] && $conExcepcion['2']['por_excepcion']);
    verificar('…y se le dice hasta cuándo',
        str_contains($conExcepcion['2']['motivo'] ?? '', 'por excepción'), (string) $conExcepcion['2']['motivo']);
    verificar('A OTRO docente sigue cerrado',
        ! $sinExcepcion['2']['abierto'], (string) $sinExcepcion['2']['motivo']);

    // Excepción vencida.
    $excepcion->update(['hasta' => now()->subDay()->toDateString()]);
    verificar('Una excepción vencida ya no abre nada',
        ! $calendario->estadoPorParcial($materiaGrupo, $docente->id)['2']['abierto']);

    // Excepción sin persona: para cualquiera de la materia.
    $excepcion->update(['hasta' => now()->addDays(3)->toDateString(), 'persona_id' => null]);

    verificar('Una excepción sin docente alcanza a cualquiera',
        $calendario->estadoPorParcial($materiaGrupo, $otro->id)['2']['abierto']
        && $calendario->estadoPorParcial($materiaGrupo, $docente->id)['2']['abierto']);

    verificar('Queda registrado quién la autorizó',
        $excepcion->fresh()->autorizada_por === $otro->id);

    echo PHP_EOL.'6. La excepción es de UNA materia'.PHP_EOL;

    $otraMateria = AsignaturaGrupo::with('grupo.ciclo', 'planMateria.esquemaEvaluacion')
        ->where('id', '!=', $materiaGrupo->id)
        ->whereHas('grupo', fn ($q) => $q->where('ciclo_id', $ciclo->id))
        ->first();

    if ($otraMateria !== null) {
        // Se le da el mismo esquema para que tenga los mismos cortes.
        CalificacionComponente::withTrashed()->whereIn(
            'esquema_evaluacion_id',
            EsquemaEvaluacion::where('plan_materia_id', $otraMateria->plan_materia_id)->select('id')
        )->forceDelete();
        EsquemaEvaluacion::where('plan_materia_id', $otraMateria->plan_materia_id)->forceDelete();
        EsquemaEvaluacion::create([
            'plan_materia_id' => $otraMateria->plan_materia_id,
            'componente' => 'examen_p2', 'parcial' => 2, 'porcentaje' => 100, 'orden' => 1,
        ]);
        $otraMateria->unsetRelation('planMateria');

        verificar('Otra materia NO hereda la excepción',
            ! $calendario->estadoPorParcial($otraMateria, $docente->id)['2']['abierto'],
            'la excepción se concede materia por materia');
    }

    echo PHP_EOL.'7. puedeCapturar y cerrados'.PHP_EOL;

    $excepcion->forceDelete();

    verificar('puedeCapturar dice sí al parcial vigente',
        $calendario->puedeCapturar($materiaGrupo, 1, $docente->id));
    verificar('puedeCapturar dice no al vencido',
        ! $calendario->puedeCapturar($materiaGrupo, 2, $docente->id));
    verificar('puedeCapturar dice sí al corte sin ventana',
        $calendario->puedeCapturar($materiaGrupo, null, $docente->id));
    verificar('cerrados() devuelve solo el motivo del vencido',
        count($calendario->cerrados($materiaGrupo, $docente->id)) === 1,
        implode(' | ', $calendario->cerrados($materiaGrupo, $docente->id)));
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
