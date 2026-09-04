<?php

/**
 * Que ninguna tabla quede RECORTADA en una pantalla estrecha.
 *
 * Se corre con `php scripts/prueba-responsive.php` desde la raíz. No toca la
 * base: lee los componentes.
 *
 * ── El defecto que vigila ──────────────────────────────────────────────────
 * Una `<table>` no encoge por debajo de su contenido. Metida en una tarjeta con
 * `overflow-hidden` —que es como se dibuja casi cada listado— sus últimas
 * columnas quedan fuera y NO HAY FORMA DE ALCANZARLAS: no se puede desplazar
 * porque nada desplaza, y la página tampoco se mueve.
 *
 * Medido en un teléfono de 375 px antes de corregirlo: el estado de cuenta del
 * alumno dibujaba 725 px en un hueco de 342, así que MONTO, SALDO, ESTATUS y
 * los botones simplemente no existían para quien lo abriera desde el celular. Y
 * es la pantalla que un padre abre desde el celular.
 *
 * ── Por qué una prueba y no sólo la corrección ─────────────────────────────
 * Se arreglaron 21 tablas de una vez. Sin red, la número 22 —la que alguien
 * escriba el mes que viene— nace con el mismo defecto, y no da error: se ve
 * perfecta en el monitor donde se programó. Esto es de la misma familia que
 * `prueba-pantallas-con-puerta`, que también lee el frontend.
 *
 * ── Qué NO comprueba ───────────────────────────────────────────────────────
 * Que la tabla se vea BIEN: eso se mide en el navegador y no se puede afirmar
 * desde el código. Sólo que exista una salida cuando no quepa.
 */

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
 * Los archivos `.vue` del proyecto.
 *
 * @return array<int, string>
 */
function componentes(string $raiz): array
{
    $encontrados = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($raiz));

    foreach ($it as $archivo) {
        if ($archivo->isFile() && $archivo->getExtension() === 'vue') {
            $encontrados[] = str_replace('\\', '/', $archivo->getPathname());
        }
    }

    sort($encontrados);

    return $encontrados;
}

/**
 * ¿La tabla de la línea `$i` está dentro de algo que desplace?
 *
 * Se sube por la SANGRÍA, que en este proyecto es consistente: el envoltorio de
 * una etiqueta es la línea anterior con menos sangría que abra un elemento. No
 * es un analizador de HTML y no pretende serlo; es suficiente para contestar
 * «¿hay un `overflow-x-auto` por encima?».
 *
 * @param  array<int, string>  $lineas
 */
function puedeDesplazarse(array $lineas, int $i): bool
{
    $sangria = strlen($lineas[$i]) - strlen(ltrim($lineas[$i]));

    for ($j = $i - 1; $j >= 0 && $sangria > 0; $j--) {
        $linea = $lineas[$j];

        if (trim($linea) === '') {
            continue;
        }

        $suya = strlen($linea) - strlen(ltrim($linea));

        if ($suya >= $sangria) {
            continue;
        }

        /*
         * Un ancestro. Se lee su etiqueta ENTERA y no sólo esta línea.
         *
         * Con varios atributos, la etiqueta se reparte en renglones y el que
         * queda a la sangría del elemento es el `>` solitario del cierre —la
         * clase vive más arriba, sangrada como un atributo—. Mirando una sola
         * línea, un envoltorio correcto parecería no desplazar, y un detector
         * que marca lo bueno enseña a ignorar sus avisos.
         */
        $desde = $j;
        while ($desde > 0 && ! str_contains($lineas[$desde], '<')) {
            $desde--;
        }

        $etiqueta = '';
        for ($k = $desde; $k < count($lineas); $k++) {
            $etiqueta .= $lineas[$k];
            if (str_contains($lineas[$k], '>')) {
                break;
            }
        }

        if (str_contains($etiqueta, 'overflow-x-auto')
            || str_contains($etiqueta, 'overflow-auto')
            || str_contains($etiqueta, 'overflow-x-scroll')) {
            return true;
        }

        $sangria = $suya;
    }

    return false;
}

/**
 * Las columnas DIRECTAS de la rejilla de la línea `$i`.
 *
 * Se identifican por sangría, igual que `puedeDesplazarse`: un hijo directo es
 * la primera línea que abre un elemento con exactamente un nivel más de
 * sangría, y su etiqueta se lee ENTERA porque con varios atributos la clase
 * baja a su propio renglón.
 *
 * @param  array<int, string>  $lineas
 * @return array<int, string>  la etiqueta completa de cada columna
 */
function columnasDeLaRejilla(array $lineas, int $i): array
{
    $sangria = strlen($lineas[$i]) - strlen(ltrim($lineas[$i]));
    $columnas = [];

    for ($j = $i + 1; $j < count($lineas); $j++) {
        $linea = $lineas[$j];

        if (trim($linea) === '') {
            continue;
        }

        $suya = strlen($linea) - strlen(ltrim($linea));

        // Se acabó la rejilla.
        if ($suya <= $sangria) {
            break;
        }

        // Sólo los hijos DIRECTOS, y sólo los que abren un elemento.
        if ($suya !== $sangria + 4 || ! preg_match('/^\s*<[a-zA-Z]/', $linea)) {
            continue;
        }

        $etiqueta = '';
        for ($k = $j; $k < count($lineas); $k++) {
            $etiqueta .= $lineas[$k];
            if (str_contains($lineas[$k], '>')) {
                break;
            }
        }

        /*
         * Y hasta dónde llega esa columna: se necesita para saber si ALGO de
         * dentro tiene ancho mínimo. Sin acotarlo, la comprobación le exigiría
         * `min-w-0` a columnas que no contienen nada que pueda estirar, y un
         * detector que marca lo bueno enseña a ignorar sus avisos.
         */
        $hasta = $j;
        for ($k = $j + 1; $k < count($lineas); $k++) {
            if (trim($lineas[$k]) === '') {
                continue;
            }
            $sub = strlen($lineas[$k]) - strlen(ltrim($lineas[$k]));
            if ($sub <= $suya) {
                break;
            }
            $hasta = $k;
        }

        $columnas[] = ['etiqueta' => $etiqueta, 'desde' => $j, 'hasta' => $hasta];
    }

    return $columnas;
}

echo PHP_EOL."\033[1mNinguna tabla se queda sin salida\033[0m".PHP_EOL;

$raiz = __DIR__.'/../resources/js';
$conTabla = 0;
$sinSalida = [];

foreach (componentes($raiz) as $archivo) {
    $lineas = explode("\n", (string) file_get_contents($archivo));

    foreach ($lineas as $i => $linea) {
        if (! preg_match('/^\s*<table[\s>]/', $linea)) {
            continue;
        }

        $conTabla++;

        if (! puedeDesplazarse($lineas, $i)) {
            $sinSalida[] = substr($archivo, strpos($archivo, 'resources/js')).':'.($i + 1);
        }
    }
}

verificar(
    'hay tablas que revisar, o esta prueba no prueba nada',
    $conTabla >= 20,
    $conTabla.' tablas en '.count(componentes($raiz)).' componentes',
);

verificar(
    'todas viven dentro de algo que desplaza en horizontal',
    $sinSalida === [],
    $sinSalida === [] ? '' : implode(' · ', array_slice($sinSalida, 0, 6)),
);

/*
 * Y la red de la red: el detector tiene que SABER distinguir. Si diera
 * siempre «sí», la comprobación de arriba pasaría con el proyecto entero roto
 * —es el defecto que este proyecto ya se cobró tres veces—.
 */
echo PHP_EOL."\033[1mEl detector distingue de verdad\033[0m".PHP_EOL;

$conSalida = [
    '            <div class="overflow-x-auto">',
    '                <table class="w-full text-sm">',
];
$sin = [
    '            <div class="tarjeta">',
    '                <table class="w-full text-sm">',
];

verificar('reconoce una tabla que SÍ puede desplazarse', puedeDesplazarse($conSalida, 1));
verificar('y delata a la que NO', ! puedeDesplazarse($sin, 1));

/*
 * ── Segunda red: la COLUMNA que no puede encoger ──────────────────────────
 *
 * Una tabla dentro de `overflow-x-auto` está bien resuelta… hasta que su
 * columna de la rejilla no encoge. Un hijo de grid o de flex nace con
 * `min-width: auto`, así que no baja del ancho mínimo de su contenido: estira a
 * su padre, y con él la PÁGINA entera. El desplazamiento de la tabla nunca
 * llega a hacer falta porque nadie la aprieta.
 *
 * Medido en la ficha de una señal a 375 px antes de corregirlo: desbordaba a
 * 506 y dejaba 24 elementos fuera de la pantalla —la columna de validar o
 * descartar entera—. La primera red no lo veía: la tabla SÍ estaba dentro de
 * algo que desplaza. El defecto estaba un nivel más arriba.
 *
 * Sólo se exige donde de verdad hace falta: una rejilla cuyo componente
 * contenga algo con ancho mínimo declarado (`min-w-[…]`). Pedírselo a todas
 * llenaría de ruido componentes donde ningún hijo puede estirar.
 */
echo PHP_EOL."\033[1mNinguna columna de rejilla estira la página\033[0m".PHP_EOL;

$rejillasMiradas = 0;
$columnasRigidas = [];

foreach (componentes(__DIR__.'/../resources/js') as $archivo) {
    $texto = (string) file_get_contents($archivo);

    // Sin nada de ancho mínimo, ninguna columna puede estirar.
    if (! str_contains($texto, 'min-w-[')) {
        continue;
    }

    $lineas = explode("\n", $texto);

    foreach ($lineas as $i => $linea) {
        if (! preg_match('/class="[^"]*\bgrid\b[^"]*grid-cols-/', $linea)) {
            continue;
        }

        $rejillasMiradas++;

        foreach (columnasDeLaRejilla($lineas, $i) as $columna) {
            $dentro = implode("
", array_slice(
                $lineas, $columna['desde'], $columna['hasta'] - $columna['desde'] + 1));

            // Sólo pesa si de verdad lleva algo que no puede encoger.
            if (! str_contains($dentro, 'min-w-[')) {
                continue;
            }

            if (! str_contains($columna['etiqueta'], 'min-w-0')) {
                $columnasRigidas[] = basename($archivo).':'.($i + 1);
                break;
            }
        }
    }
}

verificar('El barrido encontró rejillas con contenido de ancho mínimo',
    $rejillasMiradas > 0, $rejillasMiradas.' rejillas');

verificar(
    'Toda columna de una rejilla con contenido ancho lleva `min-w-0`',
    $columnasRigidas === [],
    $columnasRigidas === [] ? '' : implode(' · ', array_slice(array_unique($columnasRigidas), 0, 8)),
);

/* Y la red de la red, otra vez: el detector tiene que distinguir. */
$conMinW0 = [
    '        <div class="grid gap-4 lg:grid-cols-3">',
    '            <section class="tarjeta min-w-0 p-5 lg:col-span-2">',
    '                <table class="w-full min-w-[28rem]">',
    '            </section>',
    '        </div>',
];
$sinMinW0 = [
    '        <div class="grid gap-4 lg:grid-cols-3">',
    '            <section class="tarjeta p-5 lg:col-span-2">',
    '                <table class="w-full min-w-[28rem]">',
    '            </section>',
    '        </div>',
];

$rigida = function (array $l): bool {
    foreach (columnasDeLaRejilla($l, 0) as $c) {
        $dentro = implode("
", array_slice($l, $c['desde'], $c['hasta'] - $c['desde'] + 1));

        if (str_contains($dentro, 'min-w-[') && ! str_contains($c['etiqueta'], 'min-w-0')) {
            return true;
        }
    }

    return false;
};

verificar('reconoce la columna que SÍ puede encoger', ! $rigida($conMinW0));
verificar('y delata a la que NO', $rigida($sinMinW0));

echo PHP_EOL.'Resultado: '.($verificaciones - $fallidas)." correctas, {$fallidas} fallidas".PHP_EOL;

exit($fallidas > 0 ? 1 : 0);
