<?php

/**
 * El «recuérdame» del login: que funcione, y que DEJE RASTRO. Con rollback.
 *
 * Se corre con `php scripts/prueba-recuerdame.php` desde la raíz.
 *
 * ── Qué se vigila, y por qué ──────────────────────────────────────────────
 * La casilla siempre funcionó para autenticar. Lo que no pasaba es que el
 * regreso por la cookie **no cruza el controlador de login** —lo resuelve el
 * guard al pedir `Auth::user()`—, así que `IniciadorSesion::finalizar()` no
 * corría y la entrada no se asentaba en `bitacora_accesos`.
 *
 * Ese es el defecto de verdad: no falla, no avisa, y el registro que existe
 * para saber quién entró **miente por omisión** — quien tenga la casilla
 * marcada deja de aparecer, y es justo la sesión que alguien puede haber
 * dejado abierta en una máquina prestada.
 *
 * ── Y la trampa que hace difícil el arreglo ───────────────────────────────
 * El evento `Login` se dispara en los DOS caminos con `remember = true`, así
 * que un oyente que mire la bandera del evento asentaría DOS entradas por cada
 * login con la casilla marcada. Lo que los separa es `viaRemember()`. Aquí se
 * comprueban las dos direcciones: que el regreso deje UNA fila, y que el login
 * normal no deje DOS.
 */

use App\Models\Identidad\BitacoraAcceso;
use App\Models\Identidad\Usuario;
use App\Models\Tenant;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

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

    echo ($ok ? "  \033[32mOK\033[39m   " : "  \033[31mFALLA\033[39m ").$que
        .($detalle !== '' ? "  [{$detalle}]" : '').PHP_EOL;
}

/**
 * Una petición HTTP de mentiras que trae SÓLO la cookie del recuerdo.
 *
 * Es lo que manda el navegador cuando la sesión ya caducó: sin sesión y con el
 * recaller. Se llama al guard directamente porque `EncryptCookies` cifra el
 * valor y aquí no hay middleware.
 */
function peticionConSoloLaCookie(string $nombre, string $valor): Request
{
    $peticion = Request::create('/panel', 'GET', server: [
        'REMOTE_ADDR' => '203.0.113.9',
        'HTTP_USER_AGENT' => 'Mozilla/5.0 (Prueba del recuerdo)',
    ]);

    $peticion->cookies->set($nombre, $valor);
    $peticion->setLaravelSession(app('session')->driver());

    app()->instance('request', $peticion);
    app('auth')->forgetGuards();
    Auth::guard('web')->setRequest($peticion);

    return $peticion;
}

$db->beginTransaction();

try {
    $usuario = Usuario::query()->where('usuario', 'demo')->firstOrFail();

    $entradasDe = fn () => BitacoraAcceso::query()
        ->where('usuario_id', $usuario->id)
        ->where('tipo', BitacoraAcceso::ENTRADA)
        ->count();

    echo '1. La cookie sale, y con lo que debe'.PHP_EOL;

    Auth::guard('web')->login($usuario, true);

    $nombre = Auth::guard('web')->getRecallerName();

    $cookie = collect(app('cookie')->getQueuedCookies())
        ->first(fn ($c) => $c->getName() === $nombre);

    verificar('Se encola la cookie del recuerdo', $cookie !== null, $nombre);

    /*
     * `httpOnly` no es un adorno: el valor lleva el token con el que se entra,
     * así que un `<script>` que lo alcanzara sería una sesión robada.
     */
    verificar('Y va httpOnly: su valor entra a la cuenta',
        $cookie?->isHttpOnly() === true);

    verificar('Dura más que la sesión, que es para lo que sirve',
        $cookie !== null && $cookie->getExpiresTime() > time() + 86400 * 30,
        $cookie === null ? '—' : round(($cookie->getExpiresTime() - time()) / 86400).' días');

    $token = $usuario->fresh()->getRememberToken();

    verificar('Y queda el token en la cuenta, que es la otra mitad',
        $token !== null && $token !== '', (string) strlen((string) $token));

    echo PHP_EOL.'2. El login NORMAL asienta UNA entrada, no dos'.PHP_EOL;

    /*
     * La trampa del arreglo: `Login` se dispara con `remember = true` también
     * aquí. Un oyente que mirara esa bandera dejaría dos filas —la del
     * controlador y la suya— por cada acceso con la casilla marcada.
     */
    $antes = $entradasDe();

    app(App\Services\IniciadorSesion::class)->finalizar($usuario, request());

    Auth::guard('web')->login($usuario, true);

    verificar('Entrar con la casilla marcada deja UNA sola fila',
        $entradasDe() - $antes === 1, ($entradasDe() - $antes).' filas');

    echo PHP_EOL.'3. Volver por la COOKIE también es entrar'.PHP_EOL;

    Auth::guard('web')->logout();
    $usuario->forceFill(['remember_token' => $token, 'conectado' => false])->save();

    $antes = $entradasDe();

    peticionConSoloLaCookie($nombre, (string) $cookie?->getValue());

    $recuperado = Auth::guard('web')->user();

    verificar('La cookie sola devuelve a la persona',
        $recuperado?->id === $usuario->id, $recuperado?->usuario ?? 'NADIE');

    verificar('Y el guard lo marca como venido del recuerdo',
        Auth::guard('web')->viaRemember() === true);

    /*
     * LO QUE SE VENÍA A ARREGLAR: la bitácora existe para saber quién entró, y
     * quien volvía por la cookie no aparecía nunca.
     */
    verificar('SE ASIENTA en la bitácora de accesos',
        $entradasDe() - $antes === 1, ($entradasDe() - $antes).' filas');

    verificar('Y la cuenta queda marcada como conectada',
        $usuario->fresh()->conectado === true);

    $fila = BitacoraAcceso::query()
        ->where('usuario_id', $usuario->id)
        ->where('tipo', BitacoraAcceso::ENTRADA)
        ->latest('id')->first();

    /*
     * Se anota CÓMO volvió. Es la misma entrada —las cifras del panel tienen que
     * contarla— pero no el mismo hecho: aquí nadie tecleó una contraseña, y para
     * quien audita una sesión dejada abierta eso es lo que quiere distinguir.
     */
    verificar('Diciendo que fue por el recuerdo, no tecleando',
        ($fila?->detalle['via'] ?? null) === 'recordado',
        json_encode($fila?->detalle));

    verificar('Con la IP y el navegador de quien volvió',
        $fila?->ip === '203.0.113.9' && str_contains((string) $fila?->agente, 'Prueba del recuerdo'),
        (string) $fila?->ip);

    echo PHP_EOL.'4. Una sola vez, no en cada petición'.PHP_EOL;

    /*
     * Tras el primer regreso el guard deja al usuario en la SESIÓN, así que las
     * peticiones siguientes ya no pasan por el recaller. Si pasaran, la bitácora
     * se llenaría de una entrada por página vista.
     */
    $antes = $entradasDe();

    Auth::guard('web')->user();
    Auth::guard('web')->user();

    verificar('Pedir el usuario otra vez NO asienta más filas',
        $entradasDe() - $antes === 0, ($entradasDe() - $antes).' filas de más');

    echo PHP_EOL.'5. Sin la cookie no entra nadie'.PHP_EOL;

    Auth::guard('web')->logout();

    peticionConSoloLaCookie($nombre, '');

    verificar('Sin cookie, nadie', Auth::guard('web')->user() === null);

    /*
     * Y con una cookie MANIPULADA tampoco: el token va dentro y se compara
     * contra el de la cuenta. Sin eso, quien supiera un id entraría escribiendo
     * la cookie a mano.
     */
    Auth::guard('web')->logout();

    peticionConSoloLaCookie($nombre, $usuario->id.'|token-inventado|'.$usuario->password);

    verificar('Con el token cambiado, tampoco',
        Auth::guard('web')->user() === null);

    echo PHP_EOL.'6. El oyente no se mete donde no le toca'.PHP_EOL;

    /*
     * SUPLANTAR no es entrar. `Auth::login()` lo llaman también
     * `Suplantador::iniciar` y `::terminar`, que tienen su propio rastro; una
     * entrada suya en la bitácora diría que esa persona inició sesión cuando no
     * lo hizo. Aquí no hay recaller de por medio, así que el oyente se calla.
     */
    Auth::guard('web')->logout();
    app()->instance('request', Request::create('/', 'GET'));
    app('auth')->forgetGuards();

    $otro = Usuario::query()->whereKeyNot($usuario->id)->firstOrFail();

    $antesOtro = BitacoraAcceso::query()
        ->where('usuario_id', $otro->id)
        ->where('tipo', BitacoraAcceso::ENTRADA)->count();

    Auth::guard('web')->login($otro);

    $despuesOtro = BitacoraAcceso::query()
        ->where('usuario_id', $otro->id)
        ->where('tipo', BitacoraAcceso::ENTRADA)->count();

    verificar('Un `Auth::login` sin recuerdo —como el de suplantar— no asienta nada',
        $despuesOtro === $antesOtro, ($despuesOtro - $antesOtro).' filas');

    /*
     * Y una cuenta de la CENTRAL tampoco. Tiene su propio guard, su propio
     * modelo y su propia bitácora; una entrada suya aquí caería en la base
     * equivocada —la de una escuela cualquiera— sobre alguien que no entró a
     * ninguna escuela.
     *
     * Se construye el caso completo, con su cookie y su regreso: sin él, la
     * guarda del modelo no se ejercita y quitarla no tumba nada.
     */
    $central = App\Models\Landlord\SuperAdmin::query()->first();

    if ($central === null) {
        verificar('Hay una cuenta central con la que comprobarlo', false);
    } else {
        $tokenCentral = $central->getRememberToken();

        Auth::guard('central')->login($central, true);

        $nombreCentral = Auth::guard('central')->getRecallerName();

        $cookieCentral = collect(app('cookie')->getQueuedCookies())
            ->first(fn ($c) => $c->getName() === $nombreCentral);

        $tokenNuevo = $central->fresh()->getRememberToken();

        Auth::guard('central')->logout();

        /*
         * `logout()` CICLA el token, así que la cookie que se capturó quedaría
         * apuntando a uno que ya no existe y el regreso no ocurriría: la
         * comprobación de abajo pasaría por no haber entrado nadie, que es la
         * razón equivocada. Se devuelve el token, igual que en el caso del
         * tenant.
         */
        $central->forceFill(['remember_token' => $tokenNuevo])->save();

        $antesTodas = BitacoraAcceso::query()->where('tipo', BitacoraAcceso::ENTRADA)->count();

        $peticion = Request::create('/', 'GET');
        $peticion->cookies->set($nombreCentral, (string) $cookieCentral?->getValue());
        $peticion->setLaravelSession(app('session')->driver());

        app()->instance('request', $peticion);
        app('auth')->forgetGuards();
        Auth::guard('central')->setRequest($peticion);

        $volvio = Auth::guard('central')->user();

        verificar('La cuenta central también vuelve por su cookie',
            $volvio !== null && $volvio->getAuthIdentifier() === $central->getAuthIdentifier(),
            $volvio === null ? 'NADIE' : 'sí');

        verificar('Y el guard central la marca como venida del recuerdo',
            Auth::guard('central')->viaRemember() === true);

        verificar('Pero NO se asienta en la bitácora de la escuela',
            BitacoraAcceso::query()->where('tipo', BitacoraAcceso::ENTRADA)->count() === $antesTodas,
            (BitacoraAcceso::query()->where('tipo', BitacoraAcceso::ENTRADA)->count() - $antesTodas).' filas de más');

        // El token de la central vive en la base CENTRAL: el rollback del
        // tenant no lo deshace, así que se devuelve a mano.
        $central->forceFill(['remember_token' => $tokenCentral])->save();
    }

    echo PHP_EOL.'7. La pantalla del login sigue ofreciéndolo'.PHP_EOL;

    $vue = (string) file_get_contents(__DIR__.'/../resources/js/Pages/Auth/Login.vue');

    verificar('El formulario tiene la casilla', str_contains($vue, 'recordarme'));

    $peticionRequest = (string) file_get_contents(__DIR__.'/../app/Http/Requests/LoginRequest.php');

    verificar('Y el servidor la lee al autenticar',
        str_contains($peticionRequest, "boolean('recordarme')"));

    verificar('La columna del token existe en `usuarios`',
        Illuminate\Support\Facades\Schema::connection('tenant')
            ->hasColumn('usuarios', 'remember_token'));

} catch (Throwable $falla) {
    $verificaciones++;
    $fallidas++;
    echo "  \033[31mFALLA\033[39m la suite murió antes de terminar: ".$falla->getMessage()
        .' ('.basename($falla->getFile()).':'.$falla->getLine().')'.PHP_EOL;
} finally {
    $db->rollBack();

    echo PHP_EOL.'Resultado: '.($verificaciones - $fallidas)." correctas, {$fallidas} fallidas".PHP_EOL;
}
