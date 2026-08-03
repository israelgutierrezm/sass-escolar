<?php

/**
 * Que ningún mensaje de validación salga en inglés.
 *
 * El error que lo destapó —«The fecha de cierre field must be a date after or
 * equal to fecha de apertura»— era de una pantalla, pero la causa era global:
 * sin archivos en `lang/es_MX` el framework cae al idioma de respaldo y TODO
 * formulario sin mensajes escritos a mano habla en inglés.
 *
 * Por eso esta prueba no revisa la pantalla de actividades: revisa las 148
 * claves del framework y, además, cada regla que el proyecto usa de verdad,
 * extraída del código. Si mañana alguien agrega una regla sin traducción, aquí
 * se cae.
 */

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$ok = 0;
$mal = 0;

function vale(string $que, bool $bien, string $detalle = ''): void
{
    global $ok, $mal;
    $bien ? $ok++ : $mal++;
    echo ($bien ? '  OK    ' : '  FALLA ').$que.($detalle !== '' ? " -> {$detalle}" : '').PHP_EOL;
}

/**
 * ¿Este texto está en inglés? Se busca vocabulario que el español no usa.
 *
 * Los marcadores se quitan ANTES de mirar. Son parte del formato, no del
 * idioma: `:value` y `:values` viven igual en la frase traducida, y buscando
 * «value» a secas la prueba señalaba como inglés media docena de mensajes que
 * estaban perfectamente en español.
 */
function pareceIngles(string $texto): bool
{
    $texto = preg_replace('/:\w+/', ' ', $texto);

    return (bool) preg_match(
        '/\b(the|must|field|is|are|not|be|when|this|that|and|please|try|again|invalid|does|doesn|record|reset|previous|next)\b/i',
        $texto,
    );
}

echo PHP_EOL.'== El idioma configurado =='.PHP_EOL;

vale('la aplicación corre en es_MX', app()->getLocale() === 'es_MX', app()->getLocale());
vale('existe lang/es_MX', is_dir(base_path('lang/es_MX')));

echo PHP_EOL.'== Las 148 claves del framework =='.PHP_EOL;

$enIngles = require base_path('vendor/laravel/framework/src/Illuminate/Translation/lang/en/validation.php');

// Sin el archivo no hay nada que comparar, y morir con un error de PHP haría
// pensar que la prueba está rota cuando el roto es el sistema.
if (! file_exists(base_path('lang/es_MX/validation.php'))) {
    vale('existe validation.php', false, 'sin él, todo el sistema valida en inglés');

    echo PHP_EOL."{$ok} en verde, {$mal} EN ROJO".PHP_EOL;

    exit(1);
}

$traducido = require base_path('lang/es_MX/validation.php');

$faltantes = [];
$sinTraducir = [];

foreach ($enIngles as $clave => $valor) {
    // `custom` y `attributes` son del proyecto, no mensajes del framework.
    if (in_array($clave, ['custom', 'attributes'], true)) {
        continue;
    }

    if (! array_key_exists($clave, $traducido)) {
        $faltantes[] = $clave;

        continue;
    }

    foreach ((array) $traducido[$clave] as $sub => $texto) {
        if (pareceIngles((string) $texto)) {
            $sinTraducir[] = $clave.(is_string($sub) ? ".{$sub}" : '');
        }
    }
}

vale('están todas las claves', $faltantes === [], implode(', ', array_slice($faltantes, 0, 8)));
vale('ninguna quedó en inglés', $sinTraducir === [], implode(', ', array_slice($sinTraducir, 0, 8)));

echo PHP_EOL.'== Los otros catálogos que se ven en pantalla =='.PHP_EOL;

foreach (['auth', 'passwords', 'pagination'] as $archivo) {
    $ruta = base_path("lang/es_MX/{$archivo}.php");

    if (! file_exists($ruta)) {
        vale("existe {$archivo}.php", false);

        continue;
    }

    $sospechosos = [];

    foreach (require $ruta as $clave => $texto) {
        // La paginación son entidades HTML («&laquo; Anterior»), no frases.
        $limpio = html_entity_decode(strip_tags((string) $texto));

        if (pareceIngles($limpio)) {
            $sospechosos[] = $clave;
        }
    }

    vale("{$archivo}.php en español", $sospechosos === [], implode(', ', $sospechosos));
}

echo PHP_EOL.'== Las reglas que el proyecto usa de verdad =='.PHP_EOL;

/*
 * Se extraen del código, no de una lista escrita a mano: así la prueba cubre
 * lo que hay hoy y avisa de lo que se agregue mañana.
 */
$reglas = [];

$archivos = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path()));

foreach ($archivos as $archivo) {
    if ($archivo->getExtension() !== 'php') {
        continue;
    }

    $codigo = file_get_contents($archivo->getPathname());

    // Reglas escritas como cadena: 'required', 'max:255', 'after_or_equal:x'
    if (preg_match_all("/'([a-z_]{3,})(?::[^']*)?'/", $codigo, $m)) {
        foreach ($m[1] as $posible) {
            if (array_key_exists($posible, $enIngles)) {
                $reglas[$posible] = true;
            }
        }
    }
}

ksort($reglas);
$usadas = array_keys($reglas);

echo '  ('.count($usadas).' reglas encontradas en app/)'.PHP_EOL;

$rotas = [];

foreach ($usadas as $regla) {
    $mensaje = trans("validation.{$regla}");

    // Las reglas con variantes devuelven un arreglo (numeric/file/string/array).
    foreach ((array) $mensaje as $texto) {
        if (! is_string($texto) || $texto === "validation.{$regla}" || pareceIngles($texto)) {
            $rotas[] = $regla;

            break;
        }
    }
}

vale('todas resuelven a español', $rotas === [], implode(', ', $rotas));

echo PHP_EOL.'== El caso que lo destapó =='.PHP_EOL;

$validador = Validator::make(
    ['abre_en' => '2026-08-10', 'cierra_en' => '2026-08-01'],
    ['cierra_en' => ['nullable', 'date', 'after_or_equal:abre_en']],
);

$mensaje = $validador->errors()->first('cierra_en');

echo "         → {$mensaje}".PHP_EOL;
vale('el mensaje de la actividad ya no está en inglés', ! pareceIngles($mensaje));
vale('nombra los campos como el usuario los ve', str_contains($mensaje, 'fecha de cierre') && str_contains($mensaje, 'fecha de apertura'));

echo PHP_EOL."{$ok} en verde".($mal ? ", {$mal} EN ROJO" : '').PHP_EOL;

exit($mal ? 1 : 0);
