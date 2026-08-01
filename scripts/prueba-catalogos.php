<?php

/**
 * Configuración / Catálogos de Académico: alta, unicidad de clave, edición y la
 * salvaguarda de no borrar lo que está en uso. Contra la BD real, con rollback.
 *
 * Se corre con `php scripts/prueba-catalogos.php` desde la raíz.
 */

$raiz = dirname(__DIR__);

require $raiz.'/vendor/autoload.php';
$app = require $raiz.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Controllers\CatalogoAcademicoController;
use App\Models\Academico\Area;
use App\Models\Academico\Asignatura;
use App\Models\Academico\Modalidad;
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
    $persona = Persona::create(['nombre' => 'Cat', 'primer_apellido' => 'Admin', 'segundo_apellido' => (string) random_int(1000, 9999)]);
    $rolId = Rol::where('name', 'director_general')->firstOrFail()->id;
    $u = Usuario::create([
        'persona_id' => $persona->id,
        'usuario' => 'prueba_cat_'.random_int(100000, 999999),
        'email' => 'prueba_cat_'.random_int(100000, 999999).'@ejemplo.mx',
        'password' => Hash::make('secreto12345'),
        'rol_activo_id' => $rolId,
    ]);
    $persona->asignacionesRol()->create(['rol_id' => $rolId, 'activo' => true]);

    return $u->fresh(['persona', 'rolActivo']);
}

function pet(string $metodo, string $uri, array $datos, Usuario $u): Request
{
    $r = Request::create($uri, $metodo, $datos);
    $r->headers->set('X-Inertia', 'true');
    app()->instance('request', $r);
    $r->setUserResolver(fn () => $u);

    return $r;
}

DB::beginTransaction();

try {
    $u = admin();
    $c = new CatalogoAcademicoController;

    echo '1. El índice agrupa los catálogos por dónde se usan'.PHP_EOL;

    $props = json_decode($c->index(pet('GET', '/academico/catalogos', [], $u))->toResponse(pet('GET', '/', [], $u))->getContent(), true)['props'];
    $claves = array_column($props['catalogos'], 'clave');

    verificar('Incluye los siete catálogos administrables',
        empty(array_diff(['clasificacion', 'area', 'descriptor', 'autorizacion', 'nivel', 'turno', 'modalidad'], $claves)),
        implode(', ', $claves));

    // Nivel de estudios pasó de landlord a tenant: debe listarse por `orden`
    // (progresión), no alfabético, y las carreras deben seguir resolviéndolo.
    $nivel = collect($props['catalogos'])->firstWhere('clave', 'nivel');
    $nombres = array_column($nivel['items'], 'nombre');

    verificar('El nivel se lista por progresión (Bachillerato antes que Licenciatura)',
        array_search('Bachillerato', $nombres, true) < array_search('Licenciatura', $nombres, true));

    verificar('Un nivel usado por una carrera aparece «en uso»',
        collect($nivel['items'])->contains(fn ($i) => $i['en_uso'] === true));

    echo PHP_EOL.'2. Alta y unicidad de clave'.PHP_EOL;

    $clave = 'mod_'.random_int(1000, 9999);
    $c->store(pet('POST', '/academico/catalogos/modalidad', ['clave' => $clave, 'nombre' => 'Híbrida vespertina'], $u), 'modalidad');

    verificar('Se agrega al catálogo', Modalidad::where('clave', $clave)->exists());

    $choco = false;

    try {
        $c->store(pet('POST', '/academico/catalogos/modalidad', ['clave' => $clave, 'nombre' => 'Otra'], $u), 'modalidad');
    } catch (ValidationException $e) {
        $choco = array_key_exists('clave', $e->errors());
    }

    verificar('Una clave repetida en el mismo catálogo se rechaza', $choco);

    echo PHP_EOL.'3. Edición'.PHP_EOL;

    $creada = Modalidad::where('clave', $clave)->first();
    $c->update(pet('PUT', "/academico/catalogos/modalidad/{$creada->id}", ['clave' => $clave, 'nombre' => 'Renombrada'], $u), 'modalidad', $creada->id);

    verificar('Se actualiza el nombre', $creada->fresh()->nombre === 'Renombrada');

    echo PHP_EOL.'4. No se borra lo que está EN USO'.PHP_EOL;

    // Un área nueva usada por una asignatura no debe poder borrarse.
    $area = Area::create(['clave' => 'ar_'.random_int(1000, 9999), 'nombre' => 'Área de prueba']);
    Asignatura::create([
        'identificador' => 'X'.random_int(1000, 9999),
        'clave' => 'XA'.random_int(1000, 9999),
        'nombre' => 'Usa el área',
        'creditos' => 5,
        // Los tipos oficiales se siembran con `clave` = id del catálogo SEP
        // ('263'), no con una clave hablada: se busca por nombre.
        'tipo_asignatura_id' => TipoAsignatura::where('nombre', 'OBLIGATORIA')->value('id'),
        'area_id' => $area->id,
    ]);

    $resp = $c->destroy('area', $area->id);

    verificar('Un área usada por una asignatura NO se elimina',
        $resp->getSession()?->get('error') !== null && Area::whereKey($area->id)->exists());

    echo PHP_EOL.'5. Sí se borra lo que NO se usa'.PHP_EOL;

    $libre = Area::create(['clave' => 'lib_'.random_int(1000, 9999), 'nombre' => 'Área libre']);
    $c->destroy('area', $libre->id);

    verificar('Un área sin uso se elimina', ! Area::whereKey($libre->id)->exists());

    echo PHP_EOL.'6. Un catálogo desconocido se rechaza'.PHP_EOL;

    $rechazado = false;

    try {
        $c->store(pet('POST', '/academico/catalogos/inventado', ['clave' => 'x', 'nombre' => 'y'], $u), 'inventado');
    } catch (ValidationException $e) {
        $rechazado = array_key_exists('catalogo', $e->errors());
    }

    verificar('Administrar un catálogo inexistente se rechaza', $rechazado);
} finally {
    DB::rollBack();
    echo PHP_EOL.'-- rollback aplicado, la base queda como estaba --'.PHP_EOL;
}

echo PHP_EOL.'Resultado: '.$ok.' correctas, '.count($fallos).' fallidas'.PHP_EOL;

if ($fallos !== []) {
    echo 'Fallaron: '.implode(' · ', $fallos).PHP_EOL;
}

exit($fallos === [] ? 0 : 1);
