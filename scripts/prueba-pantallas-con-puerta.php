<?php

/**
 * Toda pantalla tiene puerta, y toda puerta lleva a donde dice.
 *
 * Se corre con `php scripts/prueba-pantallas-con-puerta.php` desde la raíz.
 *
 * ── La CLASE de defecto que vigila ────────────────────────────────────────
 * Algo construido, con su ruta y su permiso, que **no se alcanza desde ningún
 * sitio**. No falla: simplemente nadie lo encuentra, y la escuela concede un
 * permiso que no abre nada. Ya pasó tres veces:
 *
 *   - `/plataforma/modulos`, el interruptor de secciones (2026-08-26);
 *   - `/escolar/servicios`, el mostrador de trámites, y
 *   - `/escolar/recursos-digitales`, la administración de los recursos digitales (2026-08-28).
 *
 * Los dos últimos tenían tarjeta de panel… apuntando a la pantalla del ALUMNO.
 *
 * Y la dirección contraria, que produce botones muertos: el menú ofrece una
 * entrada con un permiso más flojo que el que la ruta exige, así que quien la
 * ve se lleva un 403. Pasó con `/finanzas/comprobantes` y
 * `/finanzas/cuentas-bancarias`, sólo que ahí lo grave era lo otro —el permiso
 * del menú era también el de la ruta, y ese permiso lo tiene el alumno—.
 *
 * ── Cómo se mide «se alcanza» ─────────────────────────────────────────────
 * Tres formas legítimas: entrada propia del menú, colgar de un `prefijo:` del
 * menú (las pestañas de sección), o estar enlazada desde otra pantalla.
 *
 * Lo tercero se comprueba en un contexto de NAVEGACIÓN —`href`, `visit(`,
 * `url=`—, no por aparecer en cualquier parte: `/escolar/servicios` aparecía
 * cuatro veces en su propio Vue, todas en `PUT`s de su formulario. Contar eso
 * habría dado la pantalla por alcanzable justamente cuando no lo estaba.
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Gate;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$verificaciones = 0;
$fallidas = 0;

function verificar(string $que, bool $bien, string $detalle = ''): void
{
    global $verificaciones, $fallidas;
    $verificaciones++;

    if ($bien) {
        echo "  \033[32mOK\033[0m   {$que}".($detalle !== '' ? "  [{$detalle}]" : '').PHP_EOL;
    } else {
        $fallidas++;
        echo "  \033[31mFALLA\033[0m {$que}".($detalle !== '' ? "  [{$detalle}]" : '').PHP_EOL;
    }
}

$raiz = dirname(__DIR__);
$catalogo = file_get_contents($raiz.'/resources/js/menu/catalogo.ts');

// ── El menú: urls, su permiso y su alterno ────────────────────────────────
preg_match_all("/\{[^{}]*url:\s*'([^']+)'[^{}]*\}/", $catalogo, $crudas, PREG_SET_ORDER);

$entradas = [];
foreach ($crudas as $e) {
    $entradas[] = [
        'url' => $e[1],
        'permiso' => preg_match("/permiso:\s*'([^']+)'/", $e[0], $p) ? $p[1] : null,
        // El menú admite un SEGUNDO permiso para las puertas derivadas…
        'o' => preg_match("/\bo:\s*'([^']+)'/", $e[0], $q) ? $q[1] : null,
        // …y uno EXIGIDO ADEMÁS, para el `can:` de sección de su grupo de rutas.
        'y' => preg_match("/\by:\s*'([^']+)'/", $e[0], $z) ? $z[1] : null,
    ];
}

$urlsDelMenu = array_flip(array_column($entradas, 'url'));

preg_match_all("/prefijo:\s*'([^']+)'/", $catalogo, $pref);
$prefijos = array_filter($pref[1], fn ($p) => $p !== '' && $p !== '/');

// ── Todo el frontend, para buscar enlaces ─────────────────────────────────
$frontend = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($raiz.'/resources/js', FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if (preg_match('/\.(vue|ts)$/', $f->getPathname())) {
        $frontend[str_replace('\\', '/', $f->getPathname())] = file_get_contents($f->getPathname());
    }
}

/**
 * ¿Alguna pantalla NAVEGA a esa dirección?
 *
 * Se excluye el propio componente que la ruta pinta: un formulario que hace
 * `PUT` a su misma URL no es una forma de llegar a ella.
 */
function laEnlazaAlguien(string $url, ?string $componente, array $frontend): bool
{
    $escapada = preg_quote($url, '/');
    $navegacion = "/(href\s*=\s*[\"'`]|-href\s*=\s*[\"'`]|url\s*=\s*[\"'`]|visit\(\s*[\"'`]|router\.get\(\s*[\"'`])".$escapada."(?![\\w-])/";

    foreach ($frontend as $ruta => $texto) {
        if ($componente !== null && str_ends_with($ruta, '/Pages/'.$componente.'.vue')) {
            continue;
        }

        if (preg_match($navegacion, $texto)) {
            return true;
        }
    }

    return false;
}

echo PHP_EOL.'1. Ninguna pantalla con permiso se queda sin puerta'.PHP_EOL;

$sinPuerta = [];
$pantallas = 0;

foreach (app('router')->getRoutes() as $r) {
    if (! in_array('GET', $r->methods(), true)) {
        continue;
    }

    $uri = '/'.$r->uri();

    // Con parámetro se llega desde su listado, no desde el menú.
    if (str_contains($uri, '{')) {
        continue;
    }

    $can = [];
    foreach ($r->gatherMiddleware() as $mw) {
        if (is_string($mw) && str_starts_with($mw, 'can:')) {
            $can[] = substr($mw, 4);
        }
    }

    // Sin permiso no es una pantalla de oficio (portales propios, públicas).
    if ($can === []) {
        continue;
    }

    [$clase, $metodo] = array_pad(explode('@', $r->getActionName()), 2, '__invoke');

    if (! class_exists($clase)) {
        continue;
    }

    $archivo = (new ReflectionClass($clase))->getFileName();
    $texto = $archivo && is_file($archivo) ? file_get_contents($archivo) : '';

    if (! str_contains($texto, 'Inertia::render')) {
        continue;   // es un endpoint, no una pantalla
    }

    $pantallas++;

    if (isset($urlsDelMenu[$uri])) {
        continue;
    }

    foreach ($prefijos as $p) {
        if (str_starts_with($uri, rtrim($p, '/').'/')) {
            continue 2;
        }
    }

    /*
     * El componente de ESE método, no el primero del archivo.
     *
     * Tomándolo del archivo se excluía el Vue equivocado: `GrupoController`
     * pinta `Grupos/Index` en `index()` y otra cosa en `create()`, así que al
     * mirar `/escolar/grupos/create` se saltaba justo el Index, que es donde
     * está el botón que lleva ahí. Dos pantallas alcanzables salían como
     * escondidas.
     */
    $componente = null;

    if (preg_match('/function '.preg_quote($metodo, '/').'\(/', $texto, $m0, PREG_OFFSET_CAPTURE)) {
        $desde = $m0[0][1];
        $hasta = strpos($texto, "\n    public function ", $desde + 1);
        $cuerpo = substr($texto, $desde, ($hasta === false ? strlen($texto) : $hasta) - $desde);

        if (preg_match("/Inertia::render\(\s*'([^']+)'/", $cuerpo, $c)) {
            $componente = $c[1];
        }
    }

    if (! laEnlazaAlguien($uri, $componente, $frontend)) {
        $sinPuerta[] = $uri.'  ('.implode(',', $can).')';
    }
}

verificar('Toda pantalla con permiso se alcanza: menú, prefijo o enlace',
    $sinPuerta === [],
    $sinPuerta === []
        ? $pantallas.' pantallas revisadas'
        : 'sin puerta: '.implode(' | ', $sinPuerta));

echo PHP_EOL.'2. Las dos que estaban escondidas, por su nombre'.PHP_EOL;

/*
 * Nombradas aparte de la comprobación general: la regla de arriba las cubre,
 * pero si alguien afloja esa regla estas dos vuelven a desaparecer en silencio,
 * y son las que motivaron la red.
 */
foreach ([
    '/escolar/servicios' => 'el mostrador de trámites',
    '/escolar/recursos-digitales' => 'la administración de los recursos digitales',
] as $url => $que) {
    verificar('Tiene entrada de menú '.$que,
        isset($urlsDelMenu[$url]),
        $url);
}

echo PHP_EOL.'3. Ninguna entrada del menú lleva a un 403'.PHP_EOL;

$rutasGet = [];
foreach (app('router')->getRoutes() as $r) {
    if (! in_array('GET', $r->methods(), true)) {
        continue;
    }

    $can = [];
    foreach ($r->gatherMiddleware() as $mw) {
        if (is_string($mw) && str_starts_with($mw, 'can:')) {
            $can[] = substr($mw, 4);
        }
    }

    $rutasGet['/'.$r->uri()] = $can;
}

$muertos = [];

foreach ($entradas as $e) {
    if (! array_key_exists($e['url'], $rutasGet)) {
        $muertos[] = $e['url'].' → no existe esa ruta';

        continue;
    }

    $exige = $rutasGet[$e['url']];
    $cubre = array_filter([$e['permiso'], $e['o'], $e['y']]);

    foreach ($exige as $permiso) {
        /*
         * Una puerta DERIVADA nunca está entre los permisos concretos de nadie
         * —se resuelve con `Gate::define`—, así que el menú declara los
         * permisos que la abren. Basta con que declare alguno.
         */
        if (Gate::has($permiso)) {
            if ($cubre === []) {
                $muertos[] = $e['url'].' → la ruta exige `'.$permiso.'` y el menú no pide nada';
            }

            continue;
        }

        if ($cubre !== [] && ! in_array($permiso, $cubre, true)) {
            $muertos[] = $e['url'].' → el menú ofrece con `'.implode(' o ', $cubre)
                .'` y la ruta exige `'.$permiso.'`';
        }
    }
}

verificar('Lo que el menú ofrece es lo que la ruta exige',
    $muertos === [],
    $muertos === []
        ? count($entradas).' entradas revisadas'
        : implode(' | ', array_slice($muertos, 0, 4)));

echo PHP_EOL.'Resultado: '.($verificaciones - $fallidas)." correctas, {$fallidas} fallidas".PHP_EOL;
