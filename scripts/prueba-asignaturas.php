<?php

/**
 * Asignaturas: Tipo a cuatro fijas, Descriptores como catálogo (todos por
 * defecto al crear) y las imágenes de diseño. Contra la BD real, con rollback.
 *
 * Se corre con `php scripts/prueba-asignaturas.php` desde la raíz.
 */

$raiz = dirname(__DIR__);

require $raiz.'/vendor/autoload.php';
$app = require $raiz.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

require __DIR__.'/apoyo-peticiones.php';

use App\Models\Academico\PlanMateria;
use App\Models\Academico\PlanEstudio;
use App\Http\Requests\GuardarAsignaturaRequest;
use App\Http\Requests\AgregarMateriaRequest;
use App\Http\Controllers\PlanMateriaController;
use App\Http\Controllers\AsignaturaController;
use App\Models\Academico\Asignatura;
use App\Models\Academico\Descriptor;
use App\Models\Academico\TipoAsignatura;
use App\Models\Identidad\Persona;
use App\Models\Identidad\Rol;
use App\Models\Identidad\Usuario;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
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

function admin(): Usuario
{
    $persona = Persona::create(['nombre' => 'Asig', 'primer_apellido' => 'Admin', 'segundo_apellido' => (string) random_int(1000, 9999)]);
    $rolId = Rol::where('name', 'director_general')->firstOrFail()->id;
    $u = Usuario::create([
        'persona_id' => $persona->id,
        'usuario' => 'prueba_asig_'.random_int(100000, 999999),
        'email' => 'prueba_asig_'.random_int(100000, 999999).'@ejemplo.mx',
        'password' => Hash::make('secreto12345'),
        'rol_activo_id' => $rolId,
    ]);
    $persona->asignacionesRol()->create(['rol_id' => $rolId, 'activo' => true]);

    return $u->fresh(['persona', 'rolActivo']);
}

function peticion(array $datos, Usuario $u): Request
{
    $r = Request::create('/academico/asignaturas', 'POST', $datos);
    app()->instance('request', $r);
    $r->setUserResolver(fn () => $u);

    return $r;
}

DB::beginTransaction();

try {
    $u = admin();
    $controlador = new AsignaturaController;

    echo '1. El Tipo quedó en cuatro fijas'.PHP_EOL;

    $tipos = TipoAsignatura::orderBy('id')->pluck('nombre')->all();
    verificar('Son exactamente Obligatoria, Optativa, Adicional, Complementaria',
        $tipos === ['Obligatoria', 'Optativa', 'Adicional', 'Complementaria'], implode(', ', $tipos));

    echo PHP_EOL.'2. El catálogo de descriptores'.PHP_EOL;

    verificar('Trae los cuatro descriptores sembrados',
        Descriptor::count() >= 4
        && Descriptor::whereIn('clave', ['bienvenida', 'contenido_tematico', 'actividades_aprendizaje', 'criterios_evaluacion'])->count() === 4);

    echo PHP_EOL.'3. El alta vive en la ficha del plan, no en un CRUD aparte'.PHP_EOL;

    /*
     * `AsignaturaController::store()` y `update()` NO EXISTEN: se retiraron al
     * unificar el editor (c67b682), y el alta pasó a
     * `PlanMateriaController::store`, que crea la asignatura y la liga al plan
     * en una sola transacción. Esta suite les seguía hablando y moría con «Call
     * to undefined method» sin ejecutar una comprobación.
     *
     * Con ello se fue también «nace con TODOS los descriptores marcados»: ahora
     * la ficha ofrece el catálogo y quien captura ELIGE cuáles aplican, que es
     * lo que se ve en la pantalla. Se comprueba lo de hoy.
     */
    $tipoObligatoria = TipoAsignatura::where('nombre', 'OBLIGATORIA')->value('id');
    $plan = PlanEstudio::query()->firstOrFail();
    $unico = uniqid();

    app(PlanMateriaController::class)->store(
        peticionDeFormulario(AgregarMateriaRequest::class, [
            'identificador' => 'ASIG-'.random_int(1000, 9999),
            'clave' => 'ASG-'.$unico,
            'nombre' => 'Asignatura de prueba',
            'creditos' => 8,
            'tipo_asignatura_id' => $tipoObligatoria,
            'periodo' => 1,
        ], $u),
        $plan,
    );

    $creada = Asignatura::where('clave', 'ASG-'.$unico)->first();

    verificar('El alta creó la asignatura', $creada !== null);
    verificar('Y la ligó al plan en el mismo paso',
        PlanMateria::where('plan_id', $plan->id)->where('asignatura_id', $creada?->id)->exists());
    verificar('Nace SIN descriptores: se eligen al capturarlos',
        $creada?->descriptores()->count() === 0, (string) $creada?->descriptores()->count());

    echo PHP_EOL.'4. Al editar se respeta la selección de descriptores'.PHP_EOL;

    $materia = PlanMateria::where('plan_id', $plan->id)->where('asignatura_id', $creada->id)->firstOrFail();
    $soloDos = Descriptor::orderBy('id')->take(2)->get();

    app(PlanMateriaController::class)->actualizarAsignatura(
        peticionDeFormulario(GuardarAsignaturaRequest::class, [
            'identificador' => $creada->identificador,
            'clave' => $creada->clave,
            'nombre' => $creada->nombre,
            'creditos' => 8,
            'tipo_asignatura_id' => $tipoObligatoria,
            'descriptores' => $soloDos->map(fn ($d) => ['descriptor_id' => $d->id, 'contenido' => '<p>x</p>'])->all(),
        ], $u, '/', 'PUT', ['materia' => $materia]),
        $plan,
        $materia,
    );

    verificar('Quedan solo los dos elegidos', $creada->fresh()->descriptores()->count() === 2);

    // Un descriptor inexistente se rechaza.
    $rechazado = false;

    try {
        peticionDeFormulario(GuardarAsignaturaRequest::class, [
            'identificador' => $creada->identificador,
            'clave' => $creada->clave,
            'nombre' => $creada->nombre,
            'creditos' => 8,
            'tipo_asignatura_id' => $tipoObligatoria,
            'descriptores' => [['descriptor_id' => 999999, 'contenido' => null]],
        ], $u, '/', 'PUT', ['materia' => $materia]);
    } catch (ValidationException $e) {
        $rechazado = array_key_exists('descriptores.0.descriptor_id', $e->errors());
    }

    verificar('Un descriptor inexistente se rechaza', $rechazado);

    echo PHP_EOL.'5. Las ranuras de imagen y sus rutas'.PHP_EOL;

    $urls = $creada->fresh()->urlsDiseno();

    verificar('Sin imágenes, las tres rutas son null',
        $urls['materia'] === null && $urls['miniatura'] === null && $urls['portada'] === null);

    // Se simula que hay archivo en una ranura y se comprueba la ruta.
    $creada->update(['imagen_miniatura_url' => 'asignaturas/x.png']);

    verificar('Con archivo, la miniatura devuelve su ruta autenticada',
        str_contains((string) $creada->fresh()->urlsDiseno()['miniatura'], "/imagen/miniatura"));
} finally {
    DB::rollBack();
    echo PHP_EOL.'-- rollback aplicado, la base queda como estaba --'.PHP_EOL;
}

echo PHP_EOL.'Resultado: '.$ok.' correctas, '.count($fallos).' fallidas'.PHP_EOL;

if ($fallos !== []) {
    echo 'Fallaron: '.implode(' · ', $fallos).PHP_EOL;
}

exit($fallos === [] ? 0 : 1);
