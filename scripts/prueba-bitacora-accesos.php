<?php

/**
 * Bitácora de accesos: el parser de user-agent y el registro de entrada/salida
 * con equipo, navegador e IP. Contra la BD real, con rollback.
 *
 * `php scripts/prueba-bitacora-accesos.php` desde la raíz.
 */

$raiz = dirname(__DIR__);

require $raiz.'/vendor/autoload.php';
$app = require $raiz.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Identidad\BitacoraAcceso;
use App\Models\Identidad\Persona;
use App\Models\Identidad\Usuario;
use App\Services\BitacoraAccesos;
use App\Support\AgenteUsuario;
use Illuminate\Http\Request;
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

function req(string $ua, string $ip): Request
{
    return Request::create('/', 'GET', [], [], [], [
        'HTTP_USER_AGENT' => $ua,
        'REMOTE_ADDR' => $ip,
    ]);
}

DB::beginTransaction();

try {
    echo '1. El parser saca navegador y equipo del user-agent'.PHP_EOL;

    $chrome = AgenteUsuario::analizar('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36');
    verificar('Chrome en Windows', $chrome['navegador'] === 'Chrome' && $chrome['equipo'] === 'Windows');

    $safari = AgenteUsuario::analizar('Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1');
    verificar('Safari en iPhone', $safari['navegador'] === 'Safari' && $safari['equipo'] === 'iPhone');

    $edge = AgenteUsuario::analizar('Mozilla/5.0 (Windows NT 10.0) AppleWebKit/537.36 Chrome/120.0 Safari/537.36 Edg/120.0');
    verificar('Edge no se confunde con Chrome', $edge['navegador'] === 'Edge');

    $firefox = AgenteUsuario::analizar('Mozilla/5.0 (Android 13; Mobile) Gecko/120.0 Firefox/120.0');
    verificar('Firefox en Android', $firefox['navegador'] === 'Firefox' && $firefox['equipo'] === 'Android');

    verificar('User-agent vacío no revienta', AgenteUsuario::analizar('')['navegador'] === null);

    echo PHP_EOL.'2. La entrada y la salida se asientan con sus datos'.PHP_EOL;

    $persona = Persona::create(['nombre' => 'Bit', 'primer_apellido' => 'Acceso', 'segundo_apellido' => (string) random_int(1000, 9999)]);
    $usuario = Usuario::create([
        'persona_id' => $persona->id,
        'usuario' => 'bit'.random_int(10000, 99999),
        'email' => 'bit'.random_int(10000, 99999).'@ejemplo.mx',
        'password' => Hash::make('secreto12345'),
    ]);

    $bitacora = app(BitacoraAccesos::class);
    $bitacora->entrada($usuario, req('Mozilla/5.0 (Windows NT 10.0) Chrome/120.0 Safari/537.36', '187.190.1.2'));
    $bitacora->salida($usuario, req('Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Safari/604.1', '187.190.1.9'));

    $entrada = BitacoraAcceso::where('persona_id', $persona->id)->where('tipo', 'entrada')->first();
    $salida = BitacoraAcceso::where('persona_id', $persona->id)->where('tipo', 'salida')->first();

    verificar('Se guardó la entrada', $entrada !== null);
    verificar('Con navegador, equipo e IP', $entrada?->navegador === 'Chrome' && $entrada?->equipo === 'Windows' && $entrada?->ip === '187.190.1.2');
    verificar('Se guardó la salida', $salida !== null && $salida->equipo === 'iPhone');
    verificar('Quedó ligada a la persona y a la cuenta', $entrada?->persona_id === $persona->id && $entrada?->usuario_id === $usuario->id);

    echo PHP_EOL.'3. Registrar nunca lanza, aunque el request no traiga agente'.PHP_EOL;

    $antes = BitacoraAcceso::count();
    $bitacora->entrada($usuario, Request::create('/', 'GET'));
    verificar('Registró aun sin user-agent', BitacoraAcceso::count() === $antes + 1);
} finally {
    DB::rollBack();
    echo PHP_EOL.'-- rollback aplicado, la base queda como estaba --'.PHP_EOL;
}

echo PHP_EOL.'Resultado: '.$ok.' correctas, '.count($fallos).' fallidas'.PHP_EOL;

if ($fallos !== []) {
    echo 'Fallaron: '.implode(' · ', $fallos).PHP_EOL;
}

exit($fallos === [] ? 0 : 1);
