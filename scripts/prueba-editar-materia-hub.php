<?php

/**
 * Editar TODO la materia desde el plan (hub): actualizarAsignatura edita datos +
 * descriptores de la asignatura sin salir de la ficha, y show() entrega la
 * asignatura completa + catálogos para las pestañas. Contra la BD real, con
 * rollback.
 *
 * `php scripts/prueba-editar-materia-hub.php` desde la raíz.
 */

$raiz = dirname(__DIR__);

require $raiz.'/vendor/autoload.php';
$app = require $raiz.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Controllers\PlanMateriaController;
use App\Models\Academico\Asignatura;
use App\Models\Academico\Descriptor;
use App\Models\Academico\PlanEstudio;
use App\Models\Academico\PlanMateria;
use App\Models\Academico\TipoAsignatura;
use App\Models\Identidad\Usuario;
use Illuminate\Http\Request;
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

function req(array $datos, string $metodo = 'PUT'): Request
{
    $r = Request::create('/', $metodo, $datos);
    // El controlador usa $request->user() en show(); se autentica un usuario.
    $r->setUserResolver(fn () => Auth::user());
    app()->instance('request', $r);

    return $r;
}

DB::beginTransaction();

try {
    // show() usa can('editar-catalogo-academico'); se autentica un super-admin.
    $admin = Usuario::query()->whereHas('persona.asignacionesRol.rol', fn ($q) => $q->where('name', 'super-admin'))->first()
        ?? Usuario::query()->first();
    Auth::login($admin);

    $ctrl = new PlanMateriaController;
    $plan = PlanEstudio::query()->firstOrFail();
    $tipo = TipoAsignatura::query()->firstOrFail();
    $desc = Descriptor::query()->firstOrFail();
    $u = uniqid();

    // Materia + asignatura de prueba.
    $asig = Asignatura::create(['identificador' => 'HUB', 'clave' => "HUB-$u", 'nombre' => 'Hub Demo', 'creditos' => 5, 'tipo_asignatura_id' => $tipo->id]);
    $materia = PlanMateria::create(['plan_id' => $plan->id, 'asignatura_id' => $asig->id, 'clave_en_plan' => "HUB-$u", 'periodo' => 1, 'tipo' => 'obligatoria']);

    echo '1. actualizarAsignatura edita datos + descriptores (sin salir → back)'.PHP_EOL;
    $resp = $ctrl->actualizarAsignatura(req([
        'identificador' => 'HUB2', 'clave' => "HUB2-$u", 'nombre' => 'Hub Editada', 'creditos' => 8,
        'tipo_asignatura_id' => $tipo->id, 'horas_teoria' => 3,
        'descriptores' => [['descriptor_id' => $desc->id, 'contenido' => '<p>Contenido hub</p>']],
    ]), $plan, $materia);
    $asig->refresh();
    verificar('Cambió el nombre y créditos', $asig->nombre === 'Hub Editada' && (float) $asig->creditos === 8.0);
    verificar('Guardó horas', (int) $asig->horas_teoria === 3);
    $asig->load('descriptores');
    verificar('Guardó el descriptor con su contenido',
        $asig->descriptores->count() === 1 && $asig->descriptores->first()->pivot->contenido === '<p>Contenido hub</p>');
    verificar('Responde con back() (redirect)', $resp instanceof Illuminate\Http\RedirectResponse);

    echo PHP_EOL.'2. show() entrega la asignatura completa + catálogos'.PHP_EOL;
    $reqI = req([], 'GET');
    $reqI->headers->set('X-Inertia', 'true');
    $props = $ctrl->show($reqI, $plan, $materia)->toResponse($reqI)->getData(true)['props'];
    verificar('Manda la asignatura con id y descriptores', ($props['asignatura']['id'] ?? null) === $asig->id && is_array($props['asignatura']['descriptores']));
    verificar('Manda catálogos (tipos y descriptores)', is_array($props['tiposAsignatura']) && is_array($props['catalogoDescriptores']));
    verificar('Manda la ubicación editable (creditos_en_plan en materia)', array_key_exists('creditos_en_plan', $props['materia']));

    echo PHP_EOL.'3. La ubicación (periodo/tipo) sigue editándose por su endpoint'.PHP_EOL;
    $ctrl->update(req(['clave_en_plan' => "HUB2-$u", 'periodo' => 5, 'tipo' => 'optativa'], 'PUT'), $plan, $materia);
    $materia->refresh();
    verificar('Cambió periodo y tipo', $materia->periodo === 5 && $materia->tipo === 'optativa');
} finally {
    DB::rollBack();
    tenancy()->end();
}

echo PHP_EOL."Resultado: {$ok} correctas, ".count($fallos).' fallidas'.PHP_EOL;

if ($fallos !== []) {
    echo 'Fallidas: '.implode(', ', $fallos).PHP_EOL;
    exit(1);
}
