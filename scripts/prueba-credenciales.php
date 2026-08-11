<?php

/**
 * Entrega de credenciales por correo: al restablecer con la casilla marcada se
 * envía el correo y se asienta en la bitácora; sin marcarla, no. Contra la BD
 * real, con rollback.
 *
 * `php scripts/prueba-credenciales.php` desde la raíz.
 */

$raiz = dirname(__DIR__);

require $raiz.'/vendor/autoload.php';
$app = require $raiz.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Controllers\UsuarioController;
use App\Mail\CredencialesAcceso;
use App\Models\Identidad\BitacoraAcceso;
use App\Models\Identidad\Persona;
use App\Models\Identidad\Usuario;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

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

function cuenta(string $email): Usuario
{
    $persona = Persona::create(['nombre' => 'Cred', 'primer_apellido' => 'Prueba', 'segundo_apellido' => (string) random_int(1000, 9999), 'email' => $email]);

    return Usuario::create([
        'persona_id' => $persona->id,
        'usuario' => 'cred'.random_int(10000, 99999),
        'email' => $email,
        'password' => Hash::make(Illuminate\Support\Str::random(40)),
        'acceso_configurado' => false,
    ]);
}

function restablecer(Usuario $u, bool $enviar): Illuminate\Http\RedirectResponse
{
    /*
     * La confirmación es OBLIGATORIA: la regla ganó `confirmed` para que un
     * dedazo al restablecer no deje a alguien fuera de su cuenta sin que nadie
     * se entere. Sin `password_confirmation` la validación rechazaba con «Las
     * contraseñas no coinciden» y la suite moría antes de comprobar el correo.
     */
    $r = Request::create('/', 'PUT', [
        'password' => 'clave123456',
        'password_confirmation' => 'clave123456',
        'enviar_credenciales' => $enviar ? 1 : 0,
    ]);
    app()->instance('request', $r);

    return (new UsuarioController)->restablecerPassword($r, $u);
}

DB::beginTransaction();

try {
    echo '1. Con la casilla marcada: se envía el correo y se registra'.PHP_EOL;

    Mail::fake();
    $u1 = cuenta('cred'.random_int(10000, 99999).'@ejemplo.mx');
    restablecer($u1, true);

    Mail::assertSent(CredencialesAcceso::class, fn ($m) => $m->hasTo($u1->email));
    verificar('Se envió el correo de credenciales', true); // si no, assertSent ya habría lanzado
    verificar('Quedó en la bitácora (credenciales_enviadas)',
        BitacoraAcceso::where('persona_id', $u1->persona_id)->where('tipo', 'credenciales_enviadas')->exists());
    verificar('El acceso quedó configurado', $u1->fresh()->acceso_configurado == true);

    echo PHP_EOL.'2. Sin marcarla: no se envía nada'.PHP_EOL;

    Mail::fake();
    $u2 = cuenta('cred'.random_int(10000, 99999).'@ejemplo.mx');
    restablecer($u2, false);

    Mail::assertNothingSent();
    verificar('No se envió correo', true);
    verificar('No hay registro de credenciales enviadas',
        ! BitacoraAcceso::where('persona_id', $u2->persona_id)->where('tipo', 'credenciales_enviadas')->exists());
    verificar('Aun así se restableció y habilitó el acceso', $u2->fresh()->acceso_configurado == true);
} finally {
    DB::rollBack();
    echo PHP_EOL.'-- rollback aplicado, la base queda como estaba --'.PHP_EOL;
}

echo PHP_EOL.'Resultado: '.$ok.' correctas, '.count($fallos).' fallidas'.PHP_EOL;

if ($fallos !== []) {
    echo 'Fallaron: '.implode(' · ', $fallos).PHP_EOL;
}

exit($fallos === [] ? 0 : 1);
