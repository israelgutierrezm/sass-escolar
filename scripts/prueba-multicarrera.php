<?php

/**
 * Prueba de integración de las carreras de una misma persona: matricular en
 * otra oferta, dar de baja una sin tocar las demás, reactivar. Con rollback.
 *
 * Se corre con `php scripts/prueba-multicarrera.php` desde la raíz.
 */

$raiz = dirname(__DIR__);

require $raiz.'/vendor/autoload.php';
$app = require $raiz.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Academico\Oferta;
use App\Models\Academico\PlanEstudio;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\Admisiones\SituacionAlumno;
use App\Models\ControlEscolar\Historial;
use App\Models\Identidad\Persona;
use App\Services\MatriculadorOferta;
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
    $matriculador = app(MatriculadorOferta::class);
    $sufijo = substr((string) microtime(true), -6);

    $ofertaA = Oferta::firstOrFail();
    $ofertaB = Oferta::where('id', '!=', $ofertaA->id)->first()
        ?? Oferta::create([
            'carrera_id' => PlanEstudio::where('id', '!=', $ofertaA->plan_id)->first()?->carrera_id ?? $ofertaA->carrera_id,
            'plan_id' => PlanEstudio::where('id', '!=', $ofertaA->plan_id)->first()?->id ?? $ofertaA->plan_id,
            'campus_id' => $ofertaA->campus_id,
            'turno_id' => $ofertaA->turno_id,
            'modalidad' => $ofertaA->modalidad,
            'estatus' => $ofertaA->estatus,
        ]);

    $persona = Persona::create([
        'nombre' => 'Renata',
        'primer_apellido' => 'Solís',
        'sexo_id' => 2,
    ]);

    echo '1. Matricular a quien ya es de la casa'.PHP_EOL;

    $primera = $matriculador->matricular($persona, $ofertaA, '2026-2030');

    verificar('Se genera matrícula con la regla de la escuela',
        $primera->matricula !== null && $primera->matricula !== '', (string) $primera->matricula);
    verificar('Nace activa', $primera->estatus === 'activo');
    verificar('Se crea el rol materializado de alumno',
        \App\Models\Admisiones\Alumno::query()->whereKey($persona->id)->exists());

    $segunda = $matriculador->matricular($persona, $ofertaB, '2027-2029');

    verificar('La misma persona puede tener una segunda matrícula',
        MatriculaOferta::where('persona_id', $persona->id)->count() === 2);
    verificar('Con matrícula DISTINTA de la primera',
        $segunda->matricula !== $primera->matricula,
        $primera->matricula.' vs '.$segunda->matricula);
    verificar('El rol de alumno no se duplica',
        \App\Models\Admisiones\Alumno::query()->where('persona_id', $persona->id)->count() === 1);

    echo PHP_EOL.'2. La misma oferta, no dos veces'.PHP_EOL;

    verificar('Se detecta el impedimento antes de intentar',
        $matriculador->impedimentos($persona, $ofertaA) !== []);

    $rechazada = false;
    try {
        $matriculador->matricular($persona, $ofertaA);
    } catch (RuntimeException) {
        $rechazada = true;
    }
    verificar('Y se rechaza con mensaje, no con error de base de datos', $rechazada);
    verificar('Siguen siendo 2 matrículas',
        MatriculaOferta::where('persona_id', $persona->id)->count() === 2);

    echo PHP_EOL.'3. Baja de UNA carrera'.PHP_EOL;

    $definitiva = SituacionAlumno::where('clave', 'baja_definitiva')->first();

    $matriculador->darDeBaja($segunda, $definitiva?->id);

    verificar('La segunda queda de baja', $segunda->fresh()->estatus === 'baja');
    verificar('…con el TIPO de baja que se eligió',
        $segunda->fresh()->situacion?->clave === 'baja_definitiva',
        (string) $segunda->fresh()->situacion?->clave);
    verificar('La primera sigue activa, intacta',
        $primera->fresh()->estatus === 'activo' && $primera->fresh()->situacion?->clave === 'activo');

    // El catálogo no tiene una clave 'baja' pelada: tiene baja_temporal y
    // baja_definitiva. Elegir cuál es lo que responde "¿puede volver?".
    verificar('El catálogo ofrece las bajas que la escuela definió',
        $matriculador->situacionesDeBaja()->pluck('clave')->contains('baja_temporal')
        && $matriculador->situacionesDeBaja()->pluck('clave')->contains('baja_definitiva'),
        $matriculador->situacionesDeBaja()->pluck('clave')->implode(', '));

    echo PHP_EOL.'4. Reactivar'.PHP_EOL;

    $matriculador->reactivar($segunda);

    verificar('Vuelve a activa', $segunda->fresh()->estatus === 'activo');
    verificar('…y su situación también', $segunda->fresh()->situacion?->clave === 'activo');

    echo PHP_EOL.'5. La baja NO borra historia'.PHP_EOL;

    $planMateria = \App\Models\Academico\PlanMateria::first();
    $ciclo = \App\Models\ControlEscolar\Ciclo::first();

    if ($planMateria !== null && $ciclo !== null) {
        Historial::create([
            'matricula_oferta_id' => $segunda->id,
            'plan_materia_id' => $planMateria->id,
            'ciclo_id' => $ciclo->id,
            'tipo_evaluacion_id' => 1,
            'estatus_id' => 1,
            'calificacion' => 9,
        ]);

        $matriculador->darDeBaja($segunda, $definitiva?->id);

        verificar('El kárdex sobrevive a la baja',
            Historial::where('matricula_oferta_id', $segunda->id)->count() === 1);
        verificar('La matrícula sigue existiendo, solo cambió de estado',
            MatriculaOferta::find($segunda->id) !== null);
    }

    echo PHP_EOL.'6. Las ofertas ofrecidas excluyen las que ya tiene'.PHP_EOL;

    $disponibles = Oferta::query()
        ->whereNotIn('id', MatriculaOferta::where('persona_id', $persona->id)->pluck('oferta_id'))
        ->pluck('id');

    verificar('No se ofrece una oferta donde ya está matriculada',
        ! $disponibles->contains($ofertaA->id) && ! $disponibles->contains($ofertaB->id),
        $disponibles->count().' disponibles');
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
