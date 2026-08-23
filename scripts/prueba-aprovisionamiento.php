<?php

/**
 * El invariante «toda persona con un rol es un usuario».
 *
 * Verifica que al materializar un docente, un alumno o un aspirante, la persona
 * queda con su rol y su cuenta de censo (sin acceso configurado), que es
 * idempotente y que quien es varias cosas tiene UNA cuenta con varios roles.
 *
 * Contra la BD real, con rollback. Crea sus propias personas: no toca a nadie.
 * Se corre con `php scripts/prueba-aprovisionamiento.php` desde la raíz.
 */

$raiz = dirname(__DIR__);

require $raiz.'/vendor/autoload.php';
$app = require $raiz.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Admisiones\Alumno;
use App\Models\Admisiones\Aspirante;
use App\Models\ControlEscolar\Docente;
use App\Models\Identidad\Persona;
use App\Models\Identidad\PersonaRol;
use App\Models\Identidad\Rol;
use App\Models\Identidad\Usuario;
use App\Services\AprovisionadorAcceso;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

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

function nuevaPersona(?string $email = null): Persona
{
    return Persona::create([
        'nombre' => 'Prueba',
        'primer_apellido' => 'Acceso',
        'segundo_apellido' => (string) random_int(10000, 99999),
        'email' => $email,
    ]);
}

function usuarioDe(int $personaId): ?Usuario
{
    return Usuario::query()->where('persona_id', $personaId)->first();
}

function tieneRol(int $personaId, string $rolClave): bool
{
    $rolId = Rol::query()->where('name', $rolClave)->value('id');

    return PersonaRol::query()
        ->where('persona_id', $personaId)
        ->where('rol_id', $rolId)
        ->where('activo', true)
        ->exists();
}

DB::beginTransaction();

try {
    $situacionDocente = DB::table('situaciones_docente')->value('id');
    $situacionAlumno = DB::table('situaciones_alumno')->where('clave', 'activo')->value('id')
        ?? DB::table('situaciones_alumno')->value('id');
    $etapaCrm = DB::table('etapas_crm')->orderBy('orden')->value('id');
    $origen = DB::table('origenes_aspirante')->value('id') ?? DB::table('origenes')->value('id');

    echo '1. Un docente recién dado de alta ES usuario'.PHP_EOL;

    $p1 = nuevaPersona('doc.'.random_int(1000, 9999).'@ejemplo.mx');
    Docente::create(['persona_id' => $p1->id, 'situacion_id' => $situacionDocente]);
    $u1 = usuarioDe($p1->id);

    verificar('El docente tiene cuenta', $u1 !== null);
    verificar('Con rol docente activo', tieneRol($p1->id, 'docente'));
    verificar('La cuenta es de censo (sin acceso aún)', $u1 !== null && $u1->acceso_configurado === false);
    verificar('Hereda el correo de la persona', $u1 !== null && $u1->email === $p1->email);
    verificar('Su rol activo quedó puesto', $u1 !== null && $u1->rol_activo_id !== null);

    echo PHP_EOL.'2. Un alumno materializado ES usuario'.PHP_EOL;

    $p2 = nuevaPersona();
    Alumno::create(['persona_id' => $p2->id, 'situacion_id' => $situacionAlumno]);

    verificar('El alumno tiene cuenta', usuarioDe($p2->id) !== null);
    verificar('Con rol alumno activo', tieneRol($p2->id, 'alumno'));
    verificar('Sin correo, la cuenta igual existe', usuarioDe($p2->id)?->email === null);

    echo PHP_EOL.'3. Un aspirante registrado ES usuario'.PHP_EOL;

    $p3 = nuevaPersona();
    Aspirante::create([
        'persona_id' => $p3->id,
        'etapa_crm_id' => $etapaCrm,
        'origen_id' => $origen,
    ]);

    verificar('El aspirante tiene cuenta', usuarioDe($p3->id) !== null);
    verificar('Con rol aspirante activo', tieneRol($p3->id, 'aspirante'));

    echo PHP_EOL.'4. Es idempotente'.PHP_EOL;

    $aprov = app(AprovisionadorAcceso::class);
    $aprov->paraPersona($p1, 'docente');
    $aprov->paraPersona($p1, 'docente');

    verificar('No duplica la cuenta',
        Usuario::query()->where('persona_id', $p1->id)->count() === 1);
    verificar('No duplica la asignación de rol',
        PersonaRol::query()->where('persona_id', $p1->id)->where('rol_id', Rol::where('name', 'docente')->value('id'))->count() === 1);

    echo PHP_EOL.'5. Quien es varias cosas tiene UNA cuenta con varios roles'.PHP_EOL;

    // El docente p1 ahora también se vuelve alumno.
    Alumno::create(['persona_id' => $p1->id, 'situacion_id' => $situacionAlumno]);

    verificar('Sigue teniendo una sola cuenta',
        Usuario::query()->where('persona_id', $p1->id)->count() === 1);
    verificar('Y ahora tiene ambos roles (docente y alumno)',
        tieneRol($p1->id, 'docente') && tieneRol($p1->id, 'alumno'));

    echo PHP_EOL.'6. La cuenta de censo no da acceso'.PHP_EOL;

    // La contraseña es aleatoria e inservible: ninguna conocida entra.
    verificar('No entra con una contraseña vacía ni obvia',
        $u1 !== null && ! Hash::check('', $u1->password) && ! Hash::check('password', $u1->password) && ! Hash::check('12345678', $u1->password));
    verificar('Marcada como acceso no configurado', usuarioDe($p1->id)?->acceso_configurado === false);
} finally {
    DB::rollBack();
    echo PHP_EOL.'-- rollback aplicado, la base queda como estaba --'.PHP_EOL;
}

echo PHP_EOL.'Resultado: '.$ok.' correctas, '.count($fallos).' fallidas'.PHP_EOL;

if ($fallos !== []) {
    echo 'Fallaron: '.implode(' · ', $fallos).PHP_EOL;
}

exit($fallos === [] ? 0 : 1);
