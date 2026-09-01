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

echo PHP_EOL.'Resultado: '.($verificaciones - $fallidas)." correctas, {$fallidas} fallidas".PHP_EOL;

exit($fallidas > 0 ? 1 : 0);
