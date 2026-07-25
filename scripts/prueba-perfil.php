<?php

/**
 * «Mi perfil»: actualizar los propios datos y cambiar la contraseña con
 * verificación de la actual. Contra la BD real, con rollback.
 *
 * Se corre con `php scripts/prueba-perfil.php` desde la raíz.
 */

$raiz = dirname(__DIR__);

require $raiz.'/vendor/autoload.php';
$app = require $raiz.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Controllers\PerfilController;
use App\Models\Identidad\Persona;
use App\Models\Identidad\Rol;
use App\Models\Identidad\Usuario;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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

/** Ejecuta un método del controlador con el usuario autenticado de verdad. */
function comoUsuario(Usuario $u, callable $fn): mixed
{
    Auth::setUser($u);

    $peticion = Request::create('/', 'PUT');
    app()->instance('request', $peticion);
    $peticion->setUserResolver(fn () => $u);

    return $fn($peticion);
}

DB::beginTransaction();

try {
    $persona = Persona::create([
        'nombre' => 'Perfil', 'primer_apellido' => 'Prueba', 'segundo_apellido' => (string) random_int(1000, 9999),
    ]);

    $usuario = Usuario::create([
        'persona_id' => $persona->id,
        'usuario' => 'prueba_perfil_'.random_int(100000, 999999),
        'email' => 'prueba_perfil_'.random_int(100000, 999999).'@ejemplo.mx',
        'password' => Hash::make('claveVieja123'),
        'rol_activo_id' => Rol::where('name', 'director_general')->firstOrFail()->id,
    ]);

    $persona->asignacionesRol()->create(['rol_id' => $usuario->rol_activo_id, 'activo' => true]);

    $controlador = new PerfilController;

    echo '1. Actualizar los propios datos'.PHP_EOL;

    comoUsuario($usuario, fn ($req) => $controlador->actualizar(
        Request::create('/mi-perfil', 'PUT', [
            'nombre' => 'Nombre Nuevo',
            'primer_apellido' => 'Apellido Nuevo',
            'segundo_apellido' => '',
            'email' => 'nuevo_'.random_int(1000, 9999).'@ejemplo.mx',
        ])->setUserResolver(fn () => $usuario),
    ));

    $persona->refresh();

    verificar('El nombre se actualizó', $persona->nombre === 'Nombre Nuevo');
    verificar('El segundo apellido vacío se guarda como null', $persona->segundo_apellido === null);

    echo PHP_EOL.'2. El correo no puede chocar con el de otra cuenta'.PHP_EOL;

    $otro = Usuario::create([
        'persona_id' => Persona::create(['nombre' => 'Otro', 'primer_apellido' => 'Correo'])->id,
        'usuario' => 'prueba_perfil_otro_'.random_int(100000, 999999),
        'email' => 'ocupado_'.random_int(1000, 9999).'@ejemplo.mx',
        'password' => Hash::make('x1234567'),
        'rol_activo_id' => $usuario->rol_activo_id,
    ]);

    $choco = false;

    try {
        $controlador->actualizar(
            Request::create('/mi-perfil', 'PUT', [
                'nombre' => 'Nombre Nuevo', 'primer_apellido' => 'Apellido Nuevo',
                'email' => $otro->email,
            ])->setUserResolver(fn () => $usuario),
        );
    } catch (ValidationException $e) {
        $choco = array_key_exists('email', $e->errors());
    }

    verificar('Un correo ya usado se rechaza', $choco);

    echo PHP_EOL.'3. Cambio de contraseña con verificación de la actual'.PHP_EOL;

    // La actual equivocada NO cambia nada.
    $rechazada = false;

    try {
        comoUsuario($usuario, fn () => $controlador->password(
            Request::create('/mi-perfil/password', 'PUT', [
                'actual' => 'esta-no-es', 'password' => 'claveNueva456', 'password_confirmation' => 'claveNueva456',
            ])->setUserResolver(fn () => $usuario),
        ));
    } catch (ValidationException $e) {
        $rechazada = array_key_exists('actual', $e->errors());
    }

    verificar('Con la contraseña actual equivocada, se rechaza', $rechazada);
    verificar('Y la contraseña NO cambió', Hash::check('claveVieja123', $usuario->fresh()->password));

    // La actual correcta sí cambia.
    comoUsuario($usuario, fn () => $controlador->password(
        Request::create('/mi-perfil/password', 'PUT', [
            'actual' => 'claveVieja123', 'password' => 'claveNueva456', 'password_confirmation' => 'claveNueva456',
        ])->setUserResolver(fn () => $usuario),
    ));

    verificar('Con la actual correcta, la contraseña cambia', Hash::check('claveNueva456', $usuario->fresh()->password));

    // La confirmación que no coincide se rechaza.
    $noCoincide = false;

    try {
        comoUsuario($usuario, fn () => $controlador->password(
            Request::create('/mi-perfil/password', 'PUT', [
                'actual' => 'claveNueva456', 'password' => 'otra12345', 'password_confirmation' => 'distinta12345',
            ])->setUserResolver(fn () => $usuario),
        ));
    } catch (ValidationException $e) {
        $noCoincide = array_key_exists('password', $e->errors());
    }

    verificar('Si la confirmación no coincide, se rechaza', $noCoincide);
} finally {
    DB::rollBack();
    echo PHP_EOL.'-- rollback aplicado, la base queda como estaba --'.PHP_EOL;
}

echo PHP_EOL.'Resultado: '.$ok.' correctas, '.count($fallos).' fallidas'.PHP_EOL;

if ($fallos !== []) {
    echo 'Fallaron: '.implode(' · ', $fallos).PHP_EOL;
}

exit($fallos === [] ? 0 : 1);
