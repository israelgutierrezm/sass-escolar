<?php

/**
 * Institución: alta, unicidad de clave, y la salvaguarda de no borrar una con
 * campus. Contra la BD real, con rollback.
 *
 * Se corre con `php scripts/prueba-instituciones.php` desde la raíz.
 */

$raiz = dirname(__DIR__);

require $raiz.'/vendor/autoload.php';
$app = require $raiz.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Controllers\InstitucionController;
use App\Models\Academico\Campus;
use App\Models\Academico\Institucion;
use App\Models\Academico\TipoCampus;
use App\Models\Identidad\Rol;
use App\Models\Identidad\Usuario;
use App\Models\Identidad\Persona;
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

function usuarioAdmin(): Usuario
{
    $persona = Persona::create(['nombre' => 'Inst', 'primer_apellido' => 'Admin', 'segundo_apellido' => (string) random_int(1000, 9999)]);
    $rolId = Rol::where('name', 'director_general')->firstOrFail()->id;

    $u = Usuario::create([
        'persona_id' => $persona->id,
        'usuario' => 'prueba_inst_'.random_int(100000, 999999),
        'email' => 'prueba_inst_'.random_int(100000, 999999).'@ejemplo.mx',
        'password' => Hash::make('secreto12345'),
        'rol_activo_id' => $rolId,
    ]);

    $persona->asignacionesRol()->create(['rol_id' => $rolId, 'activo' => true]);

    return $u->fresh(['persona', 'rolActivo']);
}

function req(array $datos, Usuario $u): Request
{
    $r = Request::create('/academico/instituciones', 'POST', $datos);
    app()->instance('request', $r);
    $r->setUserResolver(fn () => $u);

    return $r;
}

DB::beginTransaction();

try {
    $admin = usuarioAdmin();
    $controlador = new InstitucionController;

    echo '1. La migración dejó una institución por defecto'.PHP_EOL;

    verificar('Existe la institución sembrada (PRINCIPAL)',
        Institucion::where('clave', 'PRINCIPAL')->exists());

    echo PHP_EOL.'2. Alta'.PHP_EOL;

    $clave = 'UNIV-'.random_int(1000, 9999);
    $controlador->store(req(['clave' => $clave, 'nombre' => 'Universidad de Prueba'], $admin));

    $creada = Institucion::where('clave', $clave)->first();
    verificar('Se crea con clave y nombre', $creada !== null && $creada->nombre === 'Universidad de Prueba');
    verificar('Sin logo, urlLogo es null', $creada?->urlLogo() === null);

    echo PHP_EOL.'3. La clave no se repite'.PHP_EOL;

    $choco = false;

    try {
        $controlador->store(req(['clave' => $clave, 'nombre' => 'Otra'], $admin));
    } catch (ValidationException $e) {
        $choco = array_key_exists('clave', $e->errors());
    }

    verificar('Una clave repetida se rechaza', $choco);

    echo PHP_EOL.'4. No se borra una institución con campus'.PHP_EOL;

    $tipo = TipoCampus::query()->first() ?? TipoCampus::create(['clave' => 'T', 'nombre' => 'Tipo']);
    Campus::create([
        'clave' => 'CMP-'.random_int(1000, 9999),
        'nombre' => 'Campus de prueba',
        'tipo_campus_id' => $tipo->id,
        'institucion_id' => $creada->id,
    ]);

    $respuesta = $controlador->destroy($creada);
    $errores = $respuesta->getSession()?->get('error');

    verificar('Con campus asociados, el borrado se bloquea',
        $errores !== null && Institucion::whereKey($creada->id)->exists());
} finally {
    DB::rollBack();
    echo PHP_EOL.'-- rollback aplicado, la base queda como estaba --'.PHP_EOL;
}

echo PHP_EOL.'Resultado: '.$ok.' correctas, '.count($fallos).' fallidas'.PHP_EOL;

if ($fallos !== []) {
    echo 'Fallaron: '.implode(' · ', $fallos).PHP_EOL;
}

exit($fallos === [] ? 0 : 1);
