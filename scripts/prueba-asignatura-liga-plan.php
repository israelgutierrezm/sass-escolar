<?php

/**
 * Al crear una asignatura desde «Asignaturas», ahora se liga a un plan: el store
 * crea la asignatura Y su plan_materia (con periodo y tipo) en una transacción.
 * Contra la BD real, con rollback.
 *
 * `php scripts/prueba-asignatura-liga-plan.php` desde la raíz.
 */

$raiz = dirname(__DIR__);

require $raiz.'/vendor/autoload.php';
$app = require $raiz.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Controllers\AsignaturaController;
use App\Models\Academico\Asignatura;
use App\Models\Academico\PlanEstudio;
use App\Models\Academico\PlanMateria;
use App\Models\Academico\TipoAsignatura;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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

function req(array $datos): Request
{
    $r = Request::create('/', 'POST', $datos);
    app()->instance('request', $r);

    return $r;
}

DB::beginTransaction();

try {
    $ctrl = new AsignaturaController;
    $plan = PlanEstudio::query()->firstOrFail();
    $tipo = TipoAsignatura::query()->firstOrFail();
    $u = uniqid();

    echo '1. Crear liga la asignatura al plan elegido (optativa)'.PHP_EOL;
    $ctrl->store(req([
        'identificador' => 'LIGA', 'clave' => "LIGA-$u", 'nombre' => 'Ligada', 'creditos' => 7,
        'tipo_asignatura_id' => $tipo->id,
        'plan_id' => $plan->id, 'periodo' => null, 'tipo_en_plan' => 'optativa',
    ]));

    $asig = Asignatura::query()->where('clave', "LIGA-$u")->first();
    verificar('Se creó la asignatura', $asig !== null);
    $pm = PlanMateria::query()->where('plan_id', $plan->id)->where('asignatura_id', $asig?->id)->first();
    verificar('Quedó ligada al plan', $pm !== null);
    verificar('Como optativa', $pm?->tipo === 'optativa');
    verificar('Clave de acta = clave de la asignatura', $pm?->clave_en_plan === "LIGA-$u");

    echo PHP_EOL.'2. Sin plan no se puede crear'.PHP_EOL;
    $antes = Asignatura::query()->count();
    $rechazado = false;
    try {
        $ctrl->store(req([
            'identificador' => 'X', 'clave' => "NOPLAN-$u", 'nombre' => 'Sin plan', 'creditos' => 3,
            'tipo_asignatura_id' => $tipo->id, 'tipo_en_plan' => 'obligatoria',
        ]));
    } catch (ValidationException $e) {
        $rechazado = isset($e->errors()['plan_id']);
    }
    verificar('Exige el plan', $rechazado);
    verificar('No dejó asignatura suelta', Asignatura::query()->count() === $antes);
} finally {
    DB::rollBack();
    tenancy()->end();
}

echo PHP_EOL."Resultado: {$ok} correctas, ".count($fallos).' fallidas'.PHP_EOL;

if ($fallos !== []) {
    echo 'Fallidas: '.implode(', ', $fallos).PHP_EOL;
    exit(1);
}
