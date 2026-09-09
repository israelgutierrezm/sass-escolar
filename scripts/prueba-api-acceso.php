<?php

/**
 * API de la app móvil, rebanada 1: el cimiento (Sanctum + código de escuela +
 * acceso/yo/salir). Con rollback.
 *
 * Se corre con `php scripts/prueba-api-acceso.php` desde la raíz.
 *
 * ── Qué se vigila ─────────────────────────────────────────────────────────
 *  1. **La regla de CÓMO se encuentra la cuenta vive en UN sitio** (correo o
 *     CURP; el mensaje de censo): la comparten la web y la app.
 *  2. **El acceso cambia credenciales por un TOKEN**; una contraseña mala no.
 *  3. **Salir revoca sólo el token de ESTE dispositivo.**
 *  4. **El código de escuela traduce a su dominio** (central); uno inexistente
 *     no filtra nada.
 *
 * El 401 sin token y el 405 de método lo cubre el middleware de la ruta
 * (`auth:sanctum`), que no pasa por el controlador; se comprobó por HTTP real
 * contra el servidor.
 */

use App\Http\Controllers\Api\AccesoApiController;
use App\Http\Controllers\Api\BuscadorDeEscuelaController;
use App\Models\Identidad\Usuario;
use App\Models\Tenant;
use App\Services\Acceso\ResolutorDeCuenta;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpKernel\Exception\HttpException;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

tenancy()->initialize(Tenant::find('demo'));

$db = DB::connection('tenant');

$verificaciones = 0;
$fallidas = 0;

function verificar(string $que, bool $ok, string $detalle = ''): void
{
    global $verificaciones, $fallidas;
    $verificaciones++;
    $ok || $fallidas++;
    echo ($ok ? "  \033[32mOK\033[39m   " : "  \033[31mFALLA\033[39m ").$que.($detalle !== '' ? "  [{$detalle}]" : '').PHP_EOL;
}

function fallo(callable $accion): ?int
{
    try {
        $accion();
    } catch (HttpException $e) {
        return $e->getStatusCode();
    } catch (ValidationException) {
        return 422;
    }

    return null;
}

/** Una petición JSON como la que manda la app. */
function peticion(array $datos = [], string $metodo = 'POST'): Request
{
    return Request::create('/', $metodo, $datos);
}

$db->beginTransaction();

try {
    $resolutor = app(ResolutorDeCuenta::class);
    $acceso = app(AccesoApiController::class);
    $buscador = app(BuscadorDeEscuelaController::class);

    // El administrador del demo: correo y contraseña documentados.
    $admin = Usuario::query()->where('email', 'demo@escuela.mx')->firstOrFail();

    // Se limpia la limitación por intentos para no arrastrarla entre corridas.
    $llave = 'api-acceso|'.Str::transliterate(Str::lower('demo@escuela.mx').'|127.0.0.1');
    RateLimiter::clear($llave);

    // ── 1. El resolutor, en UN sitio ────────────────────────────────────────
    echo PHP_EOL.'1. Cómo se encuentra la cuenta (lo comparten web y app)'.PHP_EOL;

    verificar('Por correo resuelve a la cuenta', $resolutor->porIdentificador('demo@escuela.mx')?->id === $admin->id);
    verificar('Vacío no resuelve a nadie', $resolutor->porIdentificador('   ') === null);
    verificar('Un correo desconocido tampoco', $resolutor->porIdentificador('nadie@ejemplo.mx') === null);

    $censo = new Usuario;
    $censo->acceso_configurado = false;
    verificar('El mensaje de una cuenta de censo dice que falta configurar acceso',
        str_contains($resolutor->mensajeDeFallo($censo), 'acceso'));
    verificar('El mensaje sin cuenta es el genérico',
        $resolutor->mensajeDeFallo(null) === 'Las credenciales no coinciden con nuestros registros.');

    // ── 2. Acceso → token ───────────────────────────────────────────────────
    echo PHP_EOL.'2. El acceso cambia credenciales por un token'.PHP_EOL;

    $tokensAntes = PersonalAccessToken::query()->count();
    $resp = json_decode($acceso->acceso(peticion(['identificador' => 'demo@escuela.mx', 'password' => 'password', 'dispositivo' => 'prueba']))->getContent(), true);
    verificar('Devuelve un token', is_string($resp['token'] ?? null) && $resp['token'] !== '');
    verificar('Y el usuario con sus facetas', ! empty($resp['usuario']['facetas']));
    verificar('El usuario trae nombre y correo', ($resp['usuario']['email'] ?? null) === 'demo@escuela.mx' && ! empty($resp['usuario']['nombre']));
    verificar('Se asentó UN token en la escuela', PersonalAccessToken::query()->count() === $tokensAntes + 1);

    RateLimiter::clear($llave);
    verificar('Una contraseña mala → 422',
        fallo(fn () => $acceso->acceso(peticion(['identificador' => 'demo@escuela.mx', 'password' => 'MALA']))) === 422);
    RateLimiter::clear($llave);
    verificar('Sin identificador → 422 (validación)',
        fallo(fn () => $acceso->acceso(peticion(['password' => 'password']))) === 422);
    verificar('Un identificador desconocido → 422',
        fallo(fn () => $acceso->acceso(peticion(['identificador' => 'nadie@ejemplo.mx', 'password' => 'x']))) === 422);

    // ── 3. Yo y salir ───────────────────────────────────────────────────────
    echo PHP_EOL.'3. Yo (con token) y salir (revoca este dispositivo)'.PHP_EOL;

    $pet = peticion([], 'GET');
    $pet->setUserResolver(fn () => $admin);
    $yo = json_decode($acceso->yo($pet)->getContent(), true);
    verificar('Yo devuelve al usuario con sus facetas', ! empty($yo['usuario']['facetas']));

    // Salir revoca SÓLO el token en uso.
    $nuevo = $admin->createToken('un-dispositivo');
    $otro = $admin->createToken('otro-dispositivo');
    $admin->withAccessToken($nuevo->accessToken);
    $petSalir = peticion([], 'POST');
    $petSalir->setUserResolver(fn () => $admin);
    $acceso->salir($petSalir);
    verificar('El token en uso se revocó', PersonalAccessToken::query()->whereKey($nuevo->accessToken->id)->doesntExist());
    verificar('El de otro dispositivo sigue vivo', PersonalAccessToken::query()->whereKey($otro->accessToken->id)->exists());

    // ── 4. Código de escuela → dominio (central) ────────────────────────────
    echo PHP_EOL.'4. El código de escuela traduce a su dominio'.PHP_EOL;

    $enc = json_decode($buscador->mostrar('demo')->getContent(), true);
    verificar('El código demo trae su dominio', ($enc['codigo'] ?? null) === 'demo' && str_contains($enc['dominio'] ?? '', 'demo'));

    $r404 = $buscador->mostrar('no-existe-esta-escuela');
    verificar('Un código inexistente → 404 sin filtrar nada',
        $r404->getStatusCode() === 404 && ! isset(json_decode($r404->getContent(), true)['dominio']));
} catch (Throwable $e) {
    verificar('La suite corrió hasta el final sin morir', false,
        get_class($e).': '.$e->getMessage().' @ '.basename($e->getFile()).':'.$e->getLine());
} finally {
    $db->rollBack();
}

echo PHP_EOL.'Resultado: '.($verificaciones - $fallidas).' correctas, '.$fallidas.' fallidas'.PHP_EOL;

exit($fallidas === 0 ? 0 : 1);
