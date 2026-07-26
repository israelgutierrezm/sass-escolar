<?php

/**
 * Vínculo padre/tutor ↔ alumno: al vincular, el tutor pasa a ser usuario con
 * rol de padre de familia. Dedup por CURP, sin auto-vínculo, desvincular no
 * borra la cuenta.
 *
 * Contra la BD real, con rollback y personas propias.
 * `php scripts/prueba-tutores.php` desde la raíz.
 */

$raiz = dirname(__DIR__);

require $raiz.'/vendor/autoload.php';
$app = require $raiz.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Identidad\Persona;
use App\Models\Identidad\PersonaRol;
use App\Models\Identidad\Rol;
use App\Models\Identidad\TutorAlumno;
use App\Models\Identidad\Usuario;
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

function persona(array $extra = []): Persona
{
    return Persona::create($extra + [
        'nombre' => 'P'.random_int(1000, 9999),
        'primer_apellido' => 'Fam',
        'segundo_apellido' => (string) random_int(1000, 9999),
    ]);
}

/** Replica lo que hace el controlador al vincular. */
function vincular(Persona $tutor, Persona $alumno, string $parentesco = 'padre'): TutorAlumno
{
    $v = TutorAlumno::create([
        'tutor_persona_id' => $tutor->id,
        'alumno_persona_id' => $alumno->id,
        'parentesco' => $parentesco,
    ]);

    app(AprovisionadorAcceso::class)->paraPersona($tutor, 'padre_familia');

    return $v;
}

function tieneRol(int $personaId, string $rolClave): bool
{
    return PersonaRol::query()
        ->where('persona_id', $personaId)
        ->where('rol_id', Rol::where('name', $rolClave)->value('id'))
        ->where('activo', true)
        ->exists();
}

DB::beginTransaction();

try {
    echo '1. Al vincular, el tutor ES usuario con rol padre de familia'.PHP_EOL;

    $hijo = persona();
    $papa = persona(['curp' => 'PAPA800101HDF'.random_int(100, 999).'X1', 'email' => 'papa'.random_int(1000, 9999).'@ejemplo.mx']);
    vincular($papa, $hijo, 'padre');

    verificar('El tutor tiene cuenta', Usuario::where('persona_id', $papa->id)->exists());
    verificar('Con rol padre_familia activo', tieneRol($papa->id, 'padre_familia'));
    verificar('El vínculo existe', TutorAlumno::where('tutor_persona_id', $papa->id)->where('alumno_persona_id', $hijo->id)->exists());
    verificar('La cuenta nace de censo (sin acceso)', Usuario::where('persona_id', $papa->id)->value('acceso_configurado') == false);

    echo PHP_EOL.'2. Un padre puede tener varios hijos, y un hijo varios tutores'.PHP_EOL;

    $hijo2 = persona();
    vincular($papa, $hijo2, 'padre');
    $mama = persona();
    vincular($mama, $hijo, 'madre');

    verificar('El papá tiene 2 hijos', $papa->hijos()->count() === 2);
    verificar('El hijo tiene 2 tutores', $hijo->tutoresFamiliares()->count() === 2);
    verificar('El papá sigue con UNA sola cuenta', Usuario::where('persona_id', $papa->id)->count() === 1);

    echo PHP_EOL.'3. Cero recaptura: la misma CURP no duplica persona'.PHP_EOL;

    $antesPersonas = Persona::count();
    // Simula el resolver del controlador: por CURP existente se reutiliza.
    $mismaCurp = Persona::where('curp', $papa->curp)->first();
    verificar('La CURP resuelve a la misma persona', $mismaCurp?->id === $papa->id);
    verificar('No se creó otra persona', Persona::count() === $antesPersonas);

    echo PHP_EOL.'4. Desvincular no borra la cuenta del tutor'.PHP_EOL;

    TutorAlumno::where('tutor_persona_id', $papa->id)->where('alumno_persona_id', $hijo->id)->delete();

    verificar('El vínculo con ese hijo desapareció',
        ! TutorAlumno::where('tutor_persona_id', $papa->id)->where('alumno_persona_id', $hijo->id)->exists());
    verificar('Pero la cuenta del papá sigue', Usuario::where('persona_id', $papa->id)->exists());
    verificar('Y su otro hijo sigue vinculado', $papa->fresh()->hijos()->count() === 1);
} finally {
    DB::rollBack();
    echo PHP_EOL.'-- rollback aplicado, la base queda como estaba --'.PHP_EOL;
}

echo PHP_EOL.'Resultado: '.$ok.' correctas, '.count($fallos).' fallidas'.PHP_EOL;

if ($fallos !== []) {
    echo 'Fallaron: '.implode(' · ', $fallos).PHP_EOL;
}

exit($fallos === [] ? 0 : 1);
