<?php

/**
 * Acceso por correo (y CURP como alternativa), y que las cuentas de censo NO
 * entran hasta tener acceso configurado.
 *
 * Prueba la validación de credenciales del guard `web` —lo que sostiene a
 * LoginRequest— sin abrir sesión (Auth::validate). Contra la BD real, con
 * rollback y personas propias.
 *
 * `php scripts/prueba-acceso.php` desde la raíz.
 */

$raiz = dirname(__DIR__);

require $raiz.'/vendor/autoload.php';
$app = require $raiz.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Identidad\Persona;
use App\Models\Identidad\Usuario;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

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

/** Resuelve la cuenta como lo hace LoginRequest: por correo o por CURP. */
function resolver(string $identificador): ?Usuario
{
    $consulta = Usuario::query()->orderByDesc('acceso_configurado');

    if (Str::contains($identificador, '@')) {
        return $consulta->where('email', $identificador)->first();
    }

    return $consulta->whereHas('persona', fn ($p) => $p->where('curp', strtoupper($identificador)))->first();
}

/** ¿Entraría con estas credenciales? (resolución + validación, sin abrir sesión) */
function entra(string $identificador, string $password): bool
{
    $usuario = resolver($identificador);

    return $usuario !== null && Auth::validate(['id' => $usuario->id, 'password' => $password]);
}

DB::beginTransaction();

try {
    $suf = random_int(100000, 999999);
    $correo = "acceso{$suf}@ejemplo.mx";
    $curp = 'ACCE900101HDF'.substr((string) $suf, 0, 3).'X1';

    $persona = Persona::create([
        'nombre' => 'Acceso', 'primer_apellido' => 'Prueba', 'segundo_apellido' => (string) $suf,
        'email' => $correo, 'curp' => $curp,
    ]);

    $cuenta = Usuario::create([
        'persona_id' => $persona->id,
        'usuario' => 'acceso'.$suf,
        'email' => $correo,
        'password' => Hash::make('secreto12345'),
        'acceso_configurado' => true,
    ]);

    echo '1. Se entra con el CORREO'.PHP_EOL;
    verificar('Correo + contraseña correcta entra', entra($correo, 'secreto12345'));
    verificar('Correo + contraseña incorrecta NO entra', ! entra($correo, 'otraCosa999'));

    echo PHP_EOL.'2. La CURP funciona como alternativa'.PHP_EOL;
    verificar('CURP + contraseña correcta entra', entra($curp, 'secreto12345'));
    verificar('CURP en minúsculas también', entra(strtolower($curp), 'secreto12345'));

    echo PHP_EOL.'3. El nombre de usuario ya NO sirve para entrar'.PHP_EOL;
    verificar('Usuario (no correo, no CURP) no resuelve', resolver('acceso'.$suf) === null);

    echo PHP_EOL.'4. Una cuenta de censo NO entra'.PHP_EOL;
    $censo = Persona::create([
        'nombre' => 'Censo', 'primer_apellido' => 'Prueba', 'segundo_apellido' => (string) $suf,
        'email' => "censo{$suf}@ejemplo.mx",
    ]);
    $cuentaCenso = Usuario::create([
        'persona_id' => $censo->id,
        'usuario' => 'censo'.$suf,
        'email' => "censo{$suf}@ejemplo.mx",
        'password' => Hash::make(Str::random(40)),
        'acceso_configurado' => false,
    ]);

    verificar('La cuenta existe (aparece en el censo)', resolver("censo{$suf}@ejemplo.mx") !== null);
    verificar('Está marcada como sin acceso', $cuentaCenso->acceso_configurado === false);
    verificar('Ninguna contraseña obvia entra', ! entra("censo{$suf}@ejemplo.mx", '') && ! entra("censo{$suf}@ejemplo.mx", 'password'));

    echo PHP_EOL.'5. Dos cuentas NO pueden compartir correo'.PHP_EOL;

    /*
     * Esta sección comprobaba a cuál de dos cuentas con el mismo correo gana el
     * resolvedor. Ese empate ya no puede existir: `usuarios.email` es ÚNICO, y
     * la prueba moría con «Duplicate entry ... for key usuarios_email_unique»
     * al montar el escenario.
     *
     * La garantía de hoy es más fuerte que la regla de desempate, así que es lo
     * que se comprueba: la base lo impide, y por eso `resolver()` nunca tiene
     * que elegir.
     */
    $otra = Persona::create([
        'nombre' => 'Otra', 'primer_apellido' => 'Homónima',
        'segundo_apellido' => (string) $suf, 'email' => $correo,
    ]);

    $repetido = false;

    try {
        Usuario::create([
            'persona_id' => $otra->id, 'usuario' => 'otra'.$suf, 'email' => $correo,
            'password' => Hash::make(Str::random(40)), 'acceso_configurado' => false,
        ]);
    } catch (Illuminate\Database\UniqueConstraintViolationException $e) {
        $repetido = true;
    }

    verificar('La base rechaza el correo repetido', $repetido);
    verificar('Y la cuenta original sigue resolviéndose', resolver($correo)?->id === $cuenta->id);
    verificar('Y sigue entrando con su contraseña', entra($correo, 'secreto12345'));
} finally {
    DB::rollBack();
    echo PHP_EOL.'-- rollback aplicado, la base queda como estaba --'.PHP_EOL;
}

echo PHP_EOL.'Resultado: '.$ok.' correctas, '.count($fallos).' fallidas'.PHP_EOL;

if ($fallos !== []) {
    echo 'Fallaron: '.implode(' · ', $fallos).PHP_EOL;
}

exit($fallos === [] ? 0 : 1);
