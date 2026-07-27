<?php

/**
 * Portal del padre: alcance por PERTENENCIA (solo sus hijos vinculados) y
 * permisos por vínculo (académico / finanzas). Contra la BD real, con rollback.
 *
 * `php scripts/prueba-portal-padre.php` desde la raíz.
 */

$raiz = dirname(__DIR__);

require $raiz.'/vendor/autoload.php';
$app = require $raiz.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Identidad\Persona;
use App\Models\Identidad\Rol;
use App\Models\Identidad\TutorAlumno;
use App\Services\AprovisionadorAcceso;
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

function persona(): Persona
{
    return Persona::create(['nombre' => 'X'.random_int(1000, 9999), 'primer_apellido' => 'Fam', 'segundo_apellido' => (string) random_int(1000, 9999)]);
}

/** Igual que el controlador: busca el vínculo que autoriza. */
function vinculoDe(int $padreId, int $hijoId): ?TutorAlumno
{
    return TutorAlumno::query()
        ->where('tutor_persona_id', $padreId)
        ->where('alumno_persona_id', $hijoId)
        ->first();
}

DB::beginTransaction();

try {
    echo '0. El rol padre_familia puede entrar al portal'.PHP_EOL;
    verificar('Concede ver-mis-hijos', Rol::where('name', 'padre_familia')->first()?->concede('ver-mis-hijos') === true);

    echo PHP_EOL.'1. Un padre vinculado a un hijo, no a otro'.PHP_EOL;

    $papa = persona();
    $hijoA = persona();
    $hijoB = persona(); // NO vinculado

    TutorAlumno::create([
        'tutor_persona_id' => $papa->id,
        'alumno_persona_id' => $hijoA->id,
        'parentesco' => 'padre',
        'puede_ver_academico' => true,
        'puede_ver_finanzas' => false,
    ]);
    app(AprovisionadorAcceso::class)->paraPersona($papa, 'padre_familia');

    verificar('Ve a su hijo vinculado', vinculoDe($papa->id, $hijoA->id) !== null);
    verificar('NO ve a un alumno ajeno (403)', vinculoDe($papa->id, $hijoB->id) === null);
    verificar('Su lista de hijos trae exactamente 1', $papa->hijos()->count() === 1);

    echo PHP_EOL.'2. Los permisos del vínculo se respetan'.PHP_EOL;

    $v = vinculoDe($papa->id, $hijoA->id);
    verificar('Puede ver lo académico', $v->puede_ver_academico === true);
    verificar('NO puede ver lo financiero', $v->puede_ver_finanzas === false);

    echo PHP_EOL.'3. La cuenta del padre existe (censo, sin acceso aún)'.PHP_EOL;
    verificar('Tiene cuenta', $papa->usuario()->exists());
    verificar('Sin acceso configurado', $papa->usuario->acceso_configurado == false);
} finally {
    DB::rollBack();
    echo PHP_EOL.'-- rollback aplicado, la base queda como estaba --'.PHP_EOL;
}

echo PHP_EOL.'Resultado: '.$ok.' correctas, '.count($fallos).' fallidas'.PHP_EOL;

if ($fallos !== []) {
    echo 'Fallaron: '.implode(' · ', $fallos).PHP_EOL;
}

exit($fallos === [] ? 0 : 1);
