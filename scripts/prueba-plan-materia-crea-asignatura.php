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

require __DIR__.'/apoyo-peticiones.php';

use App\Http\Requests\AgregarMateriaRequest;
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

/**
 * El alta pide su FormRequest; la edición de ubicación sigue con una `Request`.
 *
 * `store` dejó de recibir `Request` cuando la validación del alta se unificó en
 * `AgregarMateriaRequest`: con la petición cruda esta suite reventaba con «must
 * be of type ...Request, Request given» antes de comprobar nada. `update`, en
 * cambio, no cambió — y pasarle el FormRequest del alta le exigiría los campos
 * de la asignatura, que ahí no se capturan.
 *
 * @param  array<string, mixed>  $datos
 */
function alta(array $datos): AgregarMateriaRequest
{
    return peticionDeFormulario(AgregarMateriaRequest::class, $datos);
}

/** @param array<string, mixed> $datos */
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
    $ctrl->store(alta([
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
    /*
     * Obligatoria u optativa NO se captura al agregar la materia: lo dice el
     * TIPO DE LA ASIGNATURA. Esta comprobación esperaba que el campo `tipo` del
     * plan siguiera al dato del formulario, y dejó de ser cierto cuando el alta
     * se unificó —mandarlo ahora no hace nada, que es justo lo que hay que
     * fijar—.
     */
    verificar('El tipo lo dicta la asignatura, no un campo del alta',
        $pm !== null && $pm->asignatura->tipo_asignatura_id === $tipo->id);
    verificar('Sin periodo (optativa)', $pm?->periodo === null);
    verificar('La clave de acta cayó a la clave de la asignatura', $pm?->clave_en_plan === "OPT-$u");

    echo PHP_EOL.'2. Editar solo cambia la ubicación (no crea otra asignatura)'.PHP_EOL;
    $antesAsig = Asignatura::query()->count();
    // La ubicación se estrechó al PERIODO: ni clave de acta ni tipo se editan
    // por aquí, así que mandarlos no debe cambiarlos.
    $tipoAntes = $pm->tipo;

    $ctrl->update(req([
        'periodo' => 3,
        'tipo' => 'obligatoria',
    ], 'PUT'), $plan, $pm);
    $pm->refresh();

    verificar('Cambió el periodo', $pm->periodo === 3, (string) $pm->periodo);
    verificar('Y el tipo no se cuela por ese endpoint', $pm->tipo === $tipoAntes, (string) $pm->tipo);
    verificar('La clave en el plan sigue siendo la de la asignatura', $pm->clave_en_plan === "OPT-$u");
    verificar('NO creó otra asignatura', Asignatura::query()->count() === $antesAsig);
    verificar('La asignatura sigue siendo la misma', $pm->asignatura_id === $asig->id);

    echo PHP_EOL.'3. Alta inválida no deja asignatura suelta (transacción)'.PHP_EOL;
    $antes = Asignatura::query()->count();
    $rechazado = false;
    try {
        // Falta tipo_asignatura_id (requerido) → ValidationException antes de crear.
        $ctrl->store(alta([
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
        $ctrl->store(alta([
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
