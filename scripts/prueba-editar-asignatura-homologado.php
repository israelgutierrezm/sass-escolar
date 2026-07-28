<?php

/**
 * Homologación del editor de asignatura: «Editar» desde la lista de Asignaturas
 * (AsignaturaController::edit) abre la MISMA ficha rica que desde el plan. Si la
 * asignatura está en un plan, redirige a su hub; si no está en ninguno, cae al
 * editor de asignatura. Contra la BD real, con rollback.
 *
 * `php scripts/prueba-editar-asignatura-homologado.php` desde la raíz.
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
use App\Models\Identidad\Usuario;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
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
    $admin = Usuario::query()->whereHas('persona.asignacionesRol.rol', fn ($q) => $q->where('name', 'super-admin'))->first()
        ?? Usuario::query()->first();
    Auth::login($admin);

    $ctrl = new AsignaturaController;
    $plan = PlanEstudio::query()->firstOrFail();
    $tipo = TipoAsignatura::query()->firstOrFail();
    $u = uniqid();

    echo '1. Asignatura EN un plan → redirige al hub del plan'.PHP_EOL;
    $enPlan = Asignatura::create(['identificador' => 'HOM', 'clave' => "HOM-$u", 'nombre' => 'Homologada', 'creditos' => 5, 'tipo_asignatura_id' => $tipo->id]);
    $materia = PlanMateria::create(['plan_id' => $plan->id, 'asignatura_id' => $enPlan->id, 'clave_en_plan' => "HOM-$u", 'periodo' => 1, 'tipo' => 'obligatoria']);

    $resp = $ctrl->edit($enPlan);
    verificar('Responde con redirect', $resp instanceof RedirectResponse);
    $destino = $resp instanceof RedirectResponse ? $resp->getTargetUrl() : '';
    verificar('Apunta al hub de su plan_materia', str_contains($destino, "/planes/{$plan->id}/materias/{$materia->id}"), $destino);

    echo PHP_EOL.'2. Asignatura en VARIOS planes → usa el plan más reciente'.PHP_EOL;
    $plan2 = PlanEstudio::query()->where('id', '!=', $plan->id)->first();
    if ($plan2 !== null) {
        $materia2 = PlanMateria::create(['plan_id' => $plan2->id, 'asignatura_id' => $enPlan->id, 'clave_en_plan' => "HOM2-$u", 'periodo' => 1, 'tipo' => 'obligatoria']);
        $destino2 = $ctrl->edit($enPlan)->getTargetUrl();
        verificar('Redirige al plan_materia más reciente (mayor id)', str_contains($destino2, "/materias/{$materia2->id}"), $destino2);
    } else {
        echo '  (omitido: no hay un segundo plan en la demo)'.PHP_EOL;
    }

    echo PHP_EOL.'3. Asignatura SIN plan → editor de asignatura (Inertia), no redirige'.PHP_EOL;
    $suelta = Asignatura::create(['identificador' => 'SUE', 'clave' => "SUE-$u", 'nombre' => 'Suelta', 'creditos' => 5, 'tipo_asignatura_id' => $tipo->id]);
    $resp3 = $ctrl->edit($suelta);
    verificar('NO redirige', ! ($resp3 instanceof RedirectResponse));
    verificar('Renderiza el formulario de asignatura', $resp3 instanceof Inertia\Response);
} finally {
    DB::rollBack();
    tenancy()->end();
}

echo PHP_EOL."Resultado: {$ok} correctas, ".count($fallos).' fallidas'.PHP_EOL;

if ($fallos !== []) {
    echo 'Fallidas: '.implode(', ', $fallos).PHP_EOL;
    exit(1);
}
