<?php

/**
 * Configuración de correo (Gmail/SMTP): singleton, cifrado y no-exposición de la
 * contraseña, guardado que conserva la contraseña en blanco, aplicación del
 * mailer y prueba de envío (Mail::fake). Contra la BD real, con rollback.
 *
 * `php scripts/prueba-correo-config.php` desde la raíz.
 */

$raiz = dirname(__DIR__);

require $raiz.'/vendor/autoload.php';
$app = require $raiz.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Controllers\CorreoConfigController;
use App\Models\Correo\CorreoConfig;
use App\Models\Identidad\Persona;
use App\Models\Identidad\Usuario;
use App\Services\Correo\CorreoService;
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

function usuario(): Usuario
{
    $p = Persona::create(['nombre' => 'Correo', 'primer_apellido' => 'Admin', 'segundo_apellido' => (string) random_int(1000, 9999)]);

    return Usuario::create([
        'persona_id' => $p->id, 'usuario' => 'correo'.random_int(10000, 99999),
        'email' => 'correo'.random_int(10000, 99999).'@ejemplo.mx', 'password' => Hash::make('secreto12345'),
    ]);
}

function req(array $datos, Usuario $u, string $metodo = 'PUT'): Request
{
    $r = Request::create('/', $metodo, $datos);
    app()->instance('request', $r);
    $r->setUserResolver(fn () => $u);

    return $r;
}

DB::beginTransaction();

try {
    $admin = usuario();
    $ctrl = new CorreoConfigController;

    echo '1. Config única con defaults de Gmail'.PHP_EOL;
    $c = CorreoConfig::actual();
    verificar('Host y puerto de Gmail por defecto', $c->host === 'smtp.gmail.com' && $c->puerto === 587 && $c->cifrado === 'tls');
    verificar('Nace desactivada', $c->activo === false);

    echo PHP_EOL.'2. Guardar: contraseña cifrada y no expuesta'.PHP_EOL;
    $ctrl->guardar(req([
        'activo' => true, 'host' => 'smtp.gmail.com', 'puerto' => 587, 'cifrado' => 'tls',
        'usuario' => 'escuela@gmail.com', 'password' => 'abcd efgh ijkl mnop',
        'remitente_nombre' => 'Instituto Demo',
    ], $admin));

    $c = CorreoConfig::actual()->fresh();
    verificar('La contraseña se lee descifrada', $c->password === 'abcd efgh ijkl mnop');
    $raw = DB::table('correo_config')->where('id', $c->id)->value('password');
    verificar('En la BD la contraseña está CIFRADA', $raw !== null && ! str_contains((string) $raw, 'abcd efgh'));

    echo PHP_EOL.'3. Guardar con contraseña en blanco NO la borra'.PHP_EOL;
    $ctrl->guardar(req([
        'activo' => true, 'host' => 'smtp.gmail.com', 'puerto' => 465, 'cifrado' => 'ssl',
        'usuario' => 'escuela@gmail.com', 'password' => '', 'remitente_nombre' => 'Instituto Demo',
    ], $admin));
    $c = CorreoConfig::actual()->fresh();
    verificar('Conservó la contraseña', $c->password === 'abcd efgh ijkl mnop');
    verificar('Actualizó puerto y cifrado', $c->puerto === 465 && $c->cifrado === 'ssl');

    echo PHP_EOL.'4. aplicar() deja el mailer apuntando a la cuenta'.PHP_EOL;
    app(CorreoService::class)->aplicar();
    verificar('mail.default pasó a smtp', config('mail.default') === 'smtp');
    verificar('host/usuario aplicados', config('mail.mailers.smtp.host') === 'smtp.gmail.com' && config('mail.mailers.smtp.username') === 'escuela@gmail.com');
    verificar('remitente = nombre configurado', config('mail.from.name') === 'Instituto Demo');
    verificar('from address cae al usuario si no hay remitente', config('mail.from.address') === 'escuela@gmail.com');

    echo PHP_EOL.'5. Probar envío (Mail::fake) y asentar resultado'.PHP_EOL;
    Mail::fake();
    $ctrl->probar(req(['destino' => 'destino@ejemplo.mx'], $admin, 'POST'), app(CorreoService::class));
    $c = CorreoConfig::actual()->fresh();
    verificar('Guardó estado de prueba ok y fecha', $c->prueba_estado === 'ok' && $c->prueba_en !== null);

    echo PHP_EOL.'6. Sin configuración utilizable, no aplica ni envía'.PHP_EOL;
    CorreoConfig::actual()->forceFill(['activo' => false])->save();
    verificar('utilizable() es false si está inactiva', CorreoConfig::actual()->fresh()->utilizable() === false);
    verificar('aplicar() devuelve false', app(CorreoService::class)->aplicar() === false);
    $r = app(CorreoService::class)->probar('x@y.mx');
    verificar('probar avisa que falta configurar', $r['ok'] === false && str_contains($r['mensaje'], 'captura y activa'));
} finally {
    DB::rollBack();
    echo PHP_EOL.'-- rollback aplicado, la base queda como estaba --'.PHP_EOL;
}

echo PHP_EOL.'Resultado: '.$ok.' correctas, '.count($fallos).' fallidas'.PHP_EOL;

if ($fallos !== []) {
    echo 'Fallaron: '.implode(' · ', $fallos).PHP_EOL;
}

exit($fallos === [] ? 0 : 1);
