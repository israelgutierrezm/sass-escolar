<?php

/**
 * Descriptores de asignatura como bloques de texto enriquecido: al guardar una
 * asignatura cada descriptor lleva su propio contenido (HTML) en el pivote, se
 * relee al editar, se puede quitar y agregar, y validación rechaza descriptores
 * inexistentes. Contra la BD real, con rollback.
 *
 * `php scripts/prueba-descriptores.php` desde la raíz.
 */

$raiz = dirname(__DIR__);

require $raiz.'/vendor/autoload.php';
$app = require $raiz.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

require __DIR__.'/apoyo-peticiones.php';

use App\Models\Identidad\Usuario;
use App\Models\Academico\PlanMateria;
use App\Models\Academico\PlanEstudio;
use App\Http\Requests\GuardarAsignaturaRequest;
use App\Http\Requests\AgregarMateriaRequest;
use App\Http\Controllers\PlanMateriaController;
use App\Http\Controllers\AsignaturaController;
use App\Models\Academico\Asignatura;
use App\Models\Academico\Descriptor;
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
    /*
     * El alta y la edición de una asignatura viven en la ficha del plan desde
     * que se unificó el editor (c67b682): `AsignaturaController::store()` y
     * `update()` se retiraron. Esta suite les seguía hablando y moría con «Call
     * to undefined method» sin comprobar nada.
     */
    $ctrl = app(PlanMateriaController::class);
    $plan = PlanEstudio::query()->firstOrFail();
    $tipo = TipoAsignatura::query()->firstOrFail();

    /** Alta de una asignatura desde la ficha del plan. */
    $alta = function (array $datos) use ($ctrl, $plan) {
        $ctrl->store(peticionDeFormulario(AgregarMateriaRequest::class, $datos + ['periodo' => 1]), $plan);
    };

    /** La materia del plan que corresponde a esa asignatura. */
    $materiaDe = fn (Asignatura $a) => PlanMateria::query()
        ->where('plan_id', $plan->id)->where('asignatura_id', $a->id)->firstOrFail();

    // Dos descriptores del catálogo para la prueba (los crea si hicieran falta).
    $d1 = Descriptor::firstOrCreate(['clave' => 'prueba_bienvenida'], ['nombre' => 'Bienvenida (prueba)']);
    $d2 = Descriptor::firstOrCreate(['clave' => 'prueba_contenido'], ['nombre' => 'Contenido (prueba)']);

    $baseDatos = fn (array $extra = []) => array_merge([
        'identificador' => 'PRB-DESC',
        'clave' => 'PRB-DESC-'.uniqid(),
        'nombre' => 'Asignatura de prueba',
        'creditos' => 6,
        'tipo_asignatura_id' => $tipo->id,
    ], $extra);

    echo '1. Alta con descriptores que llevan contenido'.PHP_EOL;
    $alta($baseDatos([
        'clave' => 'PRB-DESC-A',
        'descriptores' => [
            ['descriptor_id' => $d1->id, 'contenido' => '<p>Hola <strong>mundo</strong></p>'],
            ['descriptor_id' => $d2->id, 'contenido' => '<h2>Temas</h2>'],
        ],
    ]));
    $asig = Asignatura::query()->where('clave', 'PRB-DESC-A')->firstOrFail();
    $asig->load('descriptores');
    verificar('Guardó los dos descriptores', $asig->descriptores->count() === 2, (string) $asig->descriptores->count());
    $pivD1 = $asig->descriptores->firstWhere('id', $d1->id);
    verificar('Conservó el HTML del primero', $pivD1?->pivot->contenido === '<p>Hola <strong>mundo</strong></p>');
    verificar('Conservó el HTML del segundo', $asig->descriptores->firstWhere('id', $d2->id)?->pivot->contenido === '<h2>Temas</h2>');

    echo PHP_EOL.'2. La ficha relee la forma {descriptor_id, nombre, contenido}'.PHP_EOL;
    $reqEdit = req([], 'GET');
    $reqEdit->headers->set('X-Inertia', 'true'); // fuerza la respuesta JSON de Inertia
    $reqEdit->setUserResolver(fn () => Usuario::query()->firstOrFail());
    $vista = $ctrl->show($reqEdit, $plan, $materiaDe($asig))->toResponse($reqEdit)->getData(true);
    $descsEdit = $vista['props']['asignatura']['descriptores'];
    verificar('Edit devuelve arreglo de descriptores', is_array($descsEdit) && count($descsEdit) === 2, (string) count($descsEdit));
    verificar('Cada uno trae descriptor_id, nombre y contenido',
        isset($descsEdit[0]['descriptor_id'], $descsEdit[0]['nombre'], $descsEdit[0]['contenido']));

    echo PHP_EOL.'3. Update cambia contenido y quita un descriptor'.PHP_EOL;
    $materia = $materiaDe($asig);

    $ctrl->actualizarAsignatura(
        peticionDeFormulario(GuardarAsignaturaRequest::class, [
            ...$baseDatos(['clave' => $asig->clave]),
            'descriptores' => [
                ['descriptor_id' => $d1->id, 'contenido' => '<p>Editado</p>'],
            ],
        ], null, '/', 'PUT', ['materia' => $materia]),
        $plan,
        $materia,
    );
    $asig->refresh()->load('descriptores');
    verificar('Quedó solo un descriptor', $asig->descriptores->count() === 1, (string) $asig->descriptores->count());
    verificar('Con el contenido actualizado', $asig->descriptores->first()?->pivot->contenido === '<p>Editado</p>');

    echo PHP_EOL.'4. Un descriptor sin contenido se guarda con contenido null'.PHP_EOL;
    $alta($baseDatos([
        'clave' => 'PRB-DESC-B',
        'descriptores' => [
            ['descriptor_id' => $d1->id],
        ],
    ]));
    $asigB = Asignatura::query()->where('clave', 'PRB-DESC-B')->firstOrFail();
    $asigB->load('descriptores');
    verificar('Se guardó el descriptor', $asigB->descriptores->count() === 1);
    verificar('Con contenido null', $asigB->descriptores->first()?->pivot->contenido === null);

    echo PHP_EOL.'5. Sin descriptores no truena y no agrega ninguno'.PHP_EOL;
    $alta($baseDatos(['clave' => 'PRB-DESC-C']));
    $asigC = Asignatura::query()->where('clave', 'PRB-DESC-C')->firstOrFail();
    verificar('Asignatura creada sin descriptores', $asigC->descriptores()->count() === 0);

    echo PHP_EOL.'6. Validación rechaza un descriptor_id inexistente'.PHP_EOL;
    $rechazado = false;
    try {
        $alta($baseDatos([
            'clave' => 'PRB-DESC-D',
            'descriptores' => [['descriptor_id' => 999999, 'contenido' => 'x']],
        ]));
    } catch (ValidationException $e) {
        $rechazado = true;
    }
    verificar('Lanza ValidationException', $rechazado);

    echo PHP_EOL.'7. Validación rechaza descriptor sin descriptor_id'.PHP_EOL;
    $rechazado2 = false;
    try {
        $alta($baseDatos([
            'clave' => 'PRB-DESC-E',
            'descriptores' => [['contenido' => 'sin id']],
        ]));
    } catch (ValidationException $e) {
        $rechazado2 = true;
    }
    verificar('Lanza ValidationException', $rechazado2);
} finally {
    DB::rollBack();
    tenancy()->end();
}

echo PHP_EOL."Resultado: {$ok} correctas, ".count($fallos).' fallidas'.PHP_EOL;

if ($fallos !== []) {
    echo 'Fallidas: '.implode(', ', $fallos).PHP_EOL;
    exit(1);
}
