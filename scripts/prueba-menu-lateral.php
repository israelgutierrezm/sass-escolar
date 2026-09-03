<?php

declare(strict_types=1);

/**
 * El árbol de la barra lateral: que no duplique, que no pierda y que cada
 * pantalla caiga en su subgrupo.
 *
 * ── Por qué esta suite existe, y por qué es la rara del cajón ──────────────
 * El menú se construye en TypeScript (`resources/js/menu/construir.ts`) y sus
 * defectos NO fallan: salen entradas repetidas, o una hoja nueva que no
 * aparece nunca para el rol que ya ordenó su menú. Las dos cosas se ven
 * mirando la barra, y sólo si a uno se le ocurre mirar la barra de un rol con
 * disposición guardada — que no es el caso normal en el demo.
 *
 * Al plegar las veintidós entradas de Finanzas en seis subgrupos, la fusión de
 * lo que falta duplicaba cada hoja movida para cualquier escuela que hubiera
 * ordenado su menú antes. Se cazó con esto.
 *
 * ── Cómo corre ────────────────────────────────────────────────────────────
 * Node ejecuta el TypeScript real (con `--experimental-strip-types`), sobre
 * una COPIA en un temporal cuyo `import` lleva la extensión: el código de la
 * aplicación lo resuelve Vite y no se toca por comodidad de una prueba.
 *
 * Si node no puede correrlo, esto FALLA en vez de saltarse: una prueba que se
 * apaga sola cuando el entorno cambia es de las que este proyecto ya tuvo que
 * reparar tres veces.
 */

require __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$raiz = dirname(__DIR__);
$menu = $raiz.'/resources/js/menu';
$temporal = sys_get_temp_dir().'/acadion-menu-'.getmypid();

@mkdir($temporal, 0777, true);

$correctas = 0;
$fallidas = 0;

try {
    copy($menu.'/catalogo.ts', $temporal.'/catalogo.ts');
    file_put_contents(
        $temporal.'/construir.ts',
        str_replace("from './catalogo'", "from './catalogo.ts'", (string) file_get_contents($menu.'/construir.ts')),
    );
    copy(__DIR__.'/menu/revisar.ts', $temporal.'/revisar.ts');

    $salida = [];
    $codigo = 0;
    exec(
        'node --experimental-strip-types '.escapeshellarg($temporal.'/revisar.ts').' 2>&1',
        $salida,
        $codigo,
    );

    $texto = implode("\n", $salida);

    foreach ($salida as $linea) {
        if (str_starts_with(trim($linea), 'ok ') || str_starts_with(trim($linea), 'FALLA ')) {
            echo '  '.trim($linea)."\n";
        }
    }

    if (preg_match('/Resultado: (\d+) correctas, (\d+) fallidas/', $texto, $m) === 1) {
        $correctas += (int) $m[1];
        $fallidas += (int) $m[2];
    } else {
        $fallidas = 1;
        echo "  FALLA node no pudo correr la revisión del menú.\n";
        echo "         Hace falta Node 22.6 o más nuevo (`--experimental-strip-types`).\n";
        echo '         Salida: '.mb_substr($texto, 0, 400)."\n";
    }
    /*
     * ── Y una comprobación que sólo se puede hacer desde PHP ───────────────
     *
     * Que cada permiso que el menú nombra sea ASIGNABLE.
     *
     * A la barra le llegan los permisos efectivos del rol activo
     * (`HandleInertiaRequests`), o sea filas de `permissions`. Un gate DERIVADO
     * —`Gate::define`— no está entre ellos, así que una entrada que lo nombre
     * no se le dibuja a NADIE, ni a dirección general. No falla, no avisa: la
     * pantalla simplemente deja de tener puerta.
     *
     * Pasó con «Cuentas bancarias», que declaraba `ver-cuentas-bancarias`
     * —derivado— y llevaba semanas invisible. Lo que se escribe es el par que
     * ABRE la puerta (`permiso` + `o`), como hace «Presupuesto».
     */
    $asignables = App\Support\CatalogoPermisos::claves();
    $ts = (string) file_get_contents($menu.'/catalogo.ts');

    preg_match_all("/(?:permiso|\bo|\by): '([a-z0-9-]+)'/", $ts, $coincidencias);

    $huerfanos = array_values(array_diff(array_unique($coincidencias[1]), $asignables));

    if ($huerfanos === []) {
        $correctas++;
        echo "  ok   todo permiso que el menú nombra es asignable\n";
    } else {
        $fallidas++;
        echo '  FALLA el menú nombra permisos que el front nunca recibe (gates derivados): '
            .implode(', ', $huerfanos)."\n";
        echo "         Escribe el par que abre la puerta: `permiso` + `o`.\n";
    }
} finally {
    foreach (glob($temporal.'/*') ?: [] as $archivo) {
        @unlink($archivo);
    }

    @rmdir($temporal);
}

echo "\nResultado: {$correctas} correctas, {$fallidas} fallidas\n";

exit($fallidas === 0 ? 0 : 1);
