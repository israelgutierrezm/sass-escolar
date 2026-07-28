<?php

/**
 * El correo es la llave del login y debe ser ÚNICO: dos cuentas con el mismo
 * correo cruzan sesiones. Se prueba:
 *  - IdentidadPersona::correoEnUso detecta el conflicto en `personas` y en
 *    `usuarios` (login), y excluye a la propia persona.
 *  - AprovisionadorAcceso NO crea un usuario con un correo ya tomado.
 *  - GuardarAspiranteRequest rechaza un correo en uso y deja pasar el de la
 *    persona que se reutiliza por CURP.
 * Contra la BD real, con rollback.
 *
 * `php scripts/prueba-correo-unico-aspirante.php` desde la raíz.
 */

$raiz = dirname(__DIR__);
require $raiz.'/vendor/autoload.php';
$app = require $raiz.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Requests\GuardarAspiranteRequest;
use App\Models\Identidad\Persona;
use App\Models\Identidad\Usuario;
use App\Services\AprovisionadorAcceso;
use App\Services\IdentidadPersona;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

tenancy()->initialize(App\Models\Tenant::find('demo'));

$ok = 0;
$fallos = [];

function verificar(string $t, bool $c, string $d = ''): void
{
    global $ok, $fallos;
    if ($c) { $ok++; echo "  OK   {$t}".($d ? "  [{$d}]" : '').PHP_EOL; }
    else { $fallos[] = $t; echo "  FALLA {$t}".($d ? "  [{$d}]" : '').PHP_EOL; }
}

/** Corre las reglas de GuardarAspiranteRequest sobre unos datos. */
function validarAspirante(array $datos): \Illuminate\Contracts\Validation\Validator
{
    $req = GuardarAspiranteRequest::create('/aspirantes', 'POST', $datos);
    $req->setContainer(app());

    $prep = new ReflectionMethod($req, 'prepareForValidation');
    $prep->setAccessible(true);
    $prep->invoke($req);

    $rules = new ReflectionMethod($req, 'rules');
    $rules->setAccessible(true);

    // route('aspirante') = null en alta → valida como creación.
    return Validator::make($req->all(), $rules->invoke($req));
}

DB::beginTransaction();

try {
    $identidad = app(IdentidadPersona::class);
    $u = substr(uniqid(), -6); // 6 chars → cabe en curp(18) con prefijo de 12
    $correoA = "ana.$u@correo.test";

    // Persona A con correo en `personas` y su usuario (login) con el mismo correo.
    $personaA = Persona::create(['nombre' => 'Ana', 'primer_apellido' => 'Prueba', 'email' => $correoA, 'curp' => "AAAA90010111$u"]);
    Usuario::create(['persona_id' => $personaA->id, 'usuario' => "ana_$u", 'email' => $correoA, 'password' => Hash::make('x'), 'acceso_configurado' => false]);

    // Persona B cuyo correo SOLO vive en `usuarios` (como el admin: persona.email null).
    $personaB = Persona::create(['nombre' => 'Beto', 'primer_apellido' => 'Login', 'email' => null, 'curp' => "BBBB90010111$u"]);
    $correoB = "beto.$u@correo.test";
    Usuario::create(['persona_id' => $personaB->id, 'usuario' => "beto_$u", 'email' => $correoB, 'password' => Hash::make('x'), 'acceso_configurado' => true]);

    echo '1. correoEnUso detecta el conflicto y excluye a la propia persona'.PHP_EOL;
    verificar('Correo en personas → conflicto', $identidad->correoEnUso($correoA)?->id === $personaA->id);
    verificar('Correo SOLO en usuarios (login) → conflicto', $identidad->correoEnUso($correoB)?->id === $personaB->id);
    verificar('Insensible a mayúsculas', $identidad->correoEnUso(strtoupper($correoA)) !== null);
    verificar('Excluye a la propia persona (edición)', $identidad->correoEnUso($correoA, $personaA->id) === null);
    verificar('Correo libre → sin conflicto', $identidad->correoEnUso("libre.$u@correo.test") === null);

    echo PHP_EOL.'2. AprovisionadorAcceso no crea usuario con correo ya tomado'.PHP_EOL;
    $personaC = Persona::create(['nombre' => 'Cira', 'primer_apellido' => 'Choque', 'email' => $correoA, 'curp' => "CCCC90010111$u"]);
    $cuenta = app(AprovisionadorAcceso::class)->paraPersona($personaC, 'aspirante');
    verificar('La cuenta nace SIN el correo ajeno (null)', $cuenta !== null && $cuenta->email === null, (string) $cuenta?->email);

    echo PHP_EOL.'3. La validación rechaza un correo en uso y deja pasar el reusado por CURP'.PHP_EOL;
    $situacionId = App\Models\Admisiones\SituacionAspirante::query()->value('id');
    $base = ['nombre' => 'X', 'primer_apellido' => 'Y', 'situacion_id' => $situacionId];

    $vDup = validarAspirante([...$base, 'email' => $correoA]);
    verificar('Rechaza correo en uso por otra persona', $vDup->errors()->has('email'), implode(',', $vDup->errors()->get('email')));

    $vLibre = validarAspirante([...$base, 'email' => "nuevo.$u@correo.test"]);
    verificar('Deja pasar un correo libre', ! $vLibre->errors()->has('email'));
} finally {
    DB::rollBack();
    tenancy()->end();
}

echo PHP_EOL."Resultado: {$ok} correctas, ".count($fallos).' fallidas'.PHP_EOL;
if ($fallos !== []) { echo 'Fallidas: '.implode(', ', $fallos).PHP_EOL; exit(1); }
