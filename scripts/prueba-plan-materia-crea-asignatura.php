<?php

/**
 * Al agregar una materia a un plan, ahora se CREA la asignatura ahí mismo (ya no
 * se elige una existente): store crea asignatura + plan_materia en una
 * transacción, respeta lo optativo (`tipo`), usa la clave de la asignatura como
 * clave de acta si no se captura otra, y la edición solo toca la ubicación (no la
 * asignatura). Contra la BD real, con rollback.
 *
 * `php scripts/prueba-plan-materia-crea-asignatura.php` desde la raíz.
 */

$raiz = dirname(__DIR__);

require $raiz.'/vendor/autoload.php';
$app = require $raiz.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Controllers\PlanMateriaController;
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

function req(array $datos, string $metodo = 'POST'): Request
{
    $r = Request::create('/', $metodo, $datos);
    app()->instance('request', $r);

    return $r;
}

DB::beginTransaction();

try {
    $ctrl = new PlanMateriaController;
    $plan = PlanEstudio::query()->firstOrFail();
    $tipo = TipoAsignatura::query()->firstOrFail();
    $u = uniqid();

    echo '1. Alta: crea la asignatura y la agrega al plan (optativa, sin periodo)'.PHP_EOL;
    $ctrl->store(req([
        'nombre' => 'Ética Profesional',
        'clave' => "OPT-$u",
        'identificador' => 'ETICA',
        'creditos' => 6,
        'tipo_asignatura_id' => $tipo->id,
        'tipo' => 'optativa',
        'periodo' => null, // optativa sin periodo fijo
    ]), $plan);

    $asig = Asignatura::query()->where('clave', "OPT-$u")->first();
    verificar('Se creó la asignatura', $asig !== null);
    $pm = PlanMateria::query()->where('plan_id', $plan->id)->where('asignatura_id', $asig?->id)->first();
    verificar('Se ligó al plan', $pm !== null);
    verificar('Quedó como optativa', $pm?->tipo === 'optativa');
    verificar('Sin periodo (optativa)', $pm?->periodo === null);
    verificar('La clave de acta cayó a la clave de la asignatura', $pm?->clave_en_plan === "OPT-$u");

    echo PHP_EOL.'2. Editar solo cambia la ubicación (no crea otra asignatura)'.PHP_EOL;
    $antesAsig = Asignatura::query()->count();
    $ctrl->update(req([
        'clave_en_plan' => "ACTA-$u",
        'periodo' => 3,
        'tipo' => 'obligatoria',
    ], 'PUT'), $plan, $pm);
    $pm->refresh();
    verificar('Cambió periodo y tipo', $pm->periodo === 3 && $pm->tipo === 'obligatoria');
    verificar('Cambió la clave de acta', $pm->clave_en_plan === "ACTA-$u");
    verificar('NO creó otra asignatura', Asignatura::query()->count() === $antesAsig);
    verificar('La asignatura sigue siendo la misma', $pm->asignatura_id === $asig->id);

    echo PHP_EOL.'3. Alta inválida no deja asignatura suelta (transacción)'.PHP_EOL;
    $antes = Asignatura::query()->count();
    $rechazado = false;
    try {
        // Falta tipo_asignatura_id (requerido) → ValidationException antes de crear.
        $ctrl->store(req([
            'nombre' => 'Rota', 'clave' => "BAD-$u", 'identificador' => 'X', 'creditos' => 3, 'tipo' => 'obligatoria',
        ]), $plan);
    } catch (ValidationException $e) {
        $rechazado = true;
    }
    verificar('Rechaza el alta incompleta', $rechazado);
    verificar('No quedó ninguna asignatura suelta', Asignatura::query()->count() === $antes);

    echo PHP_EOL.'4. Clave de asignatura duplicada se rechaza'.PHP_EOL;
    $dup = false;
    try {
        $ctrl->store(req([
            'nombre' => 'Otra', 'clave' => "OPT-$u", 'identificador' => 'Y', 'creditos' => 3,
            'tipo_asignatura_id' => $tipo->id, 'tipo' => 'obligatoria',
        ]), $plan);
    } catch (ValidationException $e) {
        $dup = isset($e->errors()['clave']);
    }
    verificar('No deja repetir la clave de asignatura', $dup);
} finally {
    DB::rollBack();
    tenancy()->end();
}

echo PHP_EOL."Resultado: {$ok} correctas, ".count($fallos).' fallidas'.PHP_EOL;

if ($fallos !== []) {
    echo 'Fallidas: '.implode(', ', $fallos).PHP_EOL;
    exit(1);
}
