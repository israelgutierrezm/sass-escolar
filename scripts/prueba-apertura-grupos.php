<?php

/**
 * Prueba de integración de la apertura de materias en lote y del filtro de
 * docentes ya asignados. Contra la base REAL del tenant demo, con rollback.
 *
 * Se corre con `php scripts/prueba-apertura-grupos.php` desde la raíz.
 */

$raiz = dirname(__DIR__);

require $raiz.'/vendor/autoload.php';
$app = require $raiz.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Academico\Asignatura;
use App\Models\Academico\Carrera;
use App\Models\Academico\PlanEstudio;
use App\Models\Academico\PlanMateria;
use App\Models\ControlEscolar\AsignaturaGrupo;
use App\Models\ControlEscolar\Docente;
use App\Models\ControlEscolar\Grupo;
use App\Models\ControlEscolar\SituacionAsignaturaGrupo;
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
    $sufijo = substr((string) microtime(true), -6);
    $plan = PlanEstudio::firstOrFail();
    $asignatura = Asignatura::firstOrFail();
    $grupo = Grupo::firstOrFail();
    $activa = SituacionAsignaturaGrupo::where('clave', 'activa')->value('id');

    // Seis materias repartidas en tres periodos.
    $materias = [];
    foreach ([1, 1, 2, 2, 3, 3] as $i => $periodo) {
        $materias[] = PlanMateria::create([
            'plan_id' => $plan->id,
            'asignatura_id' => $asignatura->id,
            'clave_en_plan' => "L{$sufijo}{$i}",
            'periodo' => $periodo,
            'tipo' => 'obligatoria',
        ]);
    }

    echo PHP_EOL.'1. Las materias traen su periodo suelto'.PHP_EOL;

    $disponibles = PlanMateria::query()
        ->where('plan_id', $plan->id)
        ->whereNotIn('id', AsignaturaGrupo::where('grupo_id', $grupo->id)->pluck('plan_materia_id'))
        ->get();

    $porPeriodo = $disponibles->groupBy('periodo')->map->count();

    verificar('Hay materias en al menos tres periodos',
        $porPeriodo->keys()->filter(fn ($p) => $p !== null)->count() >= 3,
        $porPeriodo->map(fn ($n, $p) => "p{$p}:{$n}")->implode(', '));

    echo PHP_EOL.'2. Apertura en lote'.PHP_EOL;

    $delPeriodo2 = collect($materias)->where('periodo', 2)->pluck('id')->all();

    DB::transaction(function () use ($delPeriodo2, $grupo, $activa): void {
        foreach ($delPeriodo2 as $id) {
            AsignaturaGrupo::create([
                'grupo_id' => $grupo->id,
                'plan_materia_id' => $id,
                'situacion_id' => $activa,
            ]);
        }
    });

    verificar('Se abren las 2 materias del periodo en una sola operación',
        AsignaturaGrupo::where('grupo_id', $grupo->id)->whereIn('plan_materia_id', $delPeriodo2)->count() === 2);

    echo PHP_EOL.'3. Reabrir lo ya abierto no duplica'.PHP_EOL;

    // Simula el filtrado del controlador: de lo pedido, solo lo que falta.
    $pedidas = [...$delPeriodo2, collect($materias)->where('periodo', 3)->first()->id];

    $yaAbiertas = AsignaturaGrupo::query()
        ->where('grupo_id', $grupo->id)
        ->whereIn('plan_materia_id', $pedidas)
        ->pluck('plan_materia_id')
        ->all();

    $nuevas = array_values(array_diff($pedidas, $yaAbiertas));

    verificar('Se detectan las 2 ya abiertas', count($yaAbiertas) === 2, count($yaAbiertas).' repetidas');
    verificar('Y solo queda 1 por abrir', count($nuevas) === 1);

    foreach ($nuevas as $id) {
        AsignaturaGrupo::create(['grupo_id' => $grupo->id, 'plan_materia_id' => $id, 'situacion_id' => $activa]);
    }

    verificar('No se duplicó ninguna',
        AsignaturaGrupo::where('grupo_id', $grupo->id)->whereIn('plan_materia_id', $pedidas)->count() === 3);

    echo PHP_EOL.'4. Una materia abierta deja de ofrecerse'.PHP_EOL;

    $abiertas = AsignaturaGrupo::where('grupo_id', $grupo->id)->pluck('plan_materia_id')->all();

    $ofrecidas = PlanMateria::query()
        ->where('plan_id', $plan->id)
        ->whereNotIn('id', $abiertas)
        ->pluck('id')
        ->all();

    verificar('Ninguna materia abierta aparece como disponible',
        array_intersect($abiertas, $ofrecidas) === []);

    echo PHP_EOL.'5. Docentes ya asignados'.PHP_EOL;

    $materiaGrupo = AsignaturaGrupo::where('grupo_id', $grupo->id)->firstOrFail();
    $docentes = Docente::with('persona')->get();

    if ($docentes->count() < 2) {
        verificar('Se necesitan 2 docentes para esta prueba', false, 'hay '.$docentes->count());
    } else {
        $titular = $docentes->first();
        $otro = $docentes->last();

        $materiaGrupo->docentes()->syncWithoutDetaching([$titular->persona_id => ['tipo' => 'titular']]);

        $asignados = $materiaGrupo->fresh('docentes')->docentes
            ->map(fn ($d) => ['id' => $d->persona_id, 'tipo' => $d->pivot->tipo]);

        verificar('El asignado se reporta con su tipo',
            $asignados->firstWhere('id', $titular->persona_id)['tipo'] === 'titular');

        // Lo que hace la pantalla: marcar como no elegibles a los ya asignados.
        $elegibles = $docentes->map(fn ($d) => [
            'id' => $d->persona_id,
            'deshabilitada' => $asignados->contains('id', $d->persona_id),
        ]);

        verificar('El docente ya asignado queda deshabilitado',
            $elegibles->firstWhere('id', $titular->persona_id)['deshabilitada'] === true);
        verificar('El otro docente sigue elegible',
            $elegibles->firstWhere('id', $otro->persona_id)['deshabilitada'] === false);
        verificar('Nadie desaparece de la lista: se marcan, no se ocultan',
            $elegibles->count() === $docentes->count(), $elegibles->count().' visibles');
    }

    echo PHP_EOL.'6. Cascada carrera → planes'.PHP_EOL;

    $carreraB = Carrera::create([
        'identificador' => "CB{$sufijo}",
        'clave' => "CB{$sufijo}",
        'nombre' => 'Carrera de prueba',
        'nivel_estudios_id' => $plan->carrera?->nivel_estudios_id ?? 1,
    ]);

    $planB = PlanEstudio::create([
        'carrera_id' => $carreraB->id,
        'clave' => "PB{$sufijo}",
        'nombre' => 'Plan 2026',   // mismo nombre que otro plan, a propósito
        'rvoe' => 'RVOE-TEST',
        'autorizacion_reconocimiento_id' => $plan->autorizacion_reconocimiento_id,
        'tipo_periodo_id' => $plan->tipo_periodo_id,
        'calificacion_minima' => 0,
        'calificacion_maxima' => 10,
        'calificacion_minima_aprobatoria' => 6,
        'minimo_creditos' => 100,
        'total_creditos' => 300,
    ]);

    $todos = PlanEstudio::query()->get(['id', 'nombre', 'carrera_id']);
    $deLaCarreraB = $todos->where('carrera_id', $carreraB->id);

    verificar('Filtrando por carrera solo queda su plan',
        $deLaCarreraB->count() === 1 && $deLaCarreraB->first()->id === $planB->id);
    verificar('Sin filtro se ven todos', $todos->count() >= 2, $todos->count().' planes');
    verificar('Dos planes distintos pueden llamarse igual (por eso importa el filtro)',
        $todos->where('nombre', 'Plan 2026')->count() >= 2,
        'planes llamados "Plan 2026": '.$todos->where('nombre', 'Plan 2026')->count());
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
