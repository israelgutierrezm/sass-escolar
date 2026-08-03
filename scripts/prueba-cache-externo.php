<?php
/**
 * Que el fallo NO se quede pegado en la caché, y que el acierto SÍ.
 *
 * Se prueba sobre CacheExterno directamente —es donde vive la regla— y luego
 * sobre las llaves reales del clima, que es lo que se rompió en producción:
 * una consulta fallida dejaba la tarjeta muda media hora.
 */

use App\Support\CacheExterno;

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$ok = 0; $mal = 0;
function vale(string $que, bool $bien) { global $ok, $mal; $bien ? $ok++ : $mal++;
    echo ($bien ? "  OK   " : "  FALLA")." $que\n"; }

echo "\n== La regla ==\n";

$llave = 'prueba:'.uniqid();
$veces = 0;

// 1. Un intento que falla devuelve null...
$r = CacheExterno::recordar($llave, 60, function () use (&$veces) { $veces++; return null; });
vale('el fallo devuelve null', $r === null);

// 2. ...y NO se queda guardado: el siguiente vuelve a intentar.
$r = CacheExterno::recordar($llave, 60, function () use (&$veces) { $veces++; return ['v' => 1]; });
vale('el fallo no se cachea (reintenta)', $veces === 2 && $r === ['v' => 1]);

// 3. Pero el acierto sí se guarda: el tercero ya no llama.
$r = CacheExterno::recordar($llave, 60, function () use (&$veces) { $veces++; return ['v' => 2]; });
vale('el acierto sí se cachea', $veces === 2 && $r === ['v' => 1]);

vale('olvidar() borra', (function () use ($llave, &$veces) {
    CacheExterno::olvidar($llave);
    CacheExterno::recordar($llave, 60, function () use (&$veces) { $veces++; return ['v' => 3]; });
    return $veces === 3;
})());
CacheExterno::olvidar($llave);

echo "\n== Los tres servicios que la usan ==\n";

foreach ([
    'ClimaDelCampus'         => App\Services\Plataforma\ClimaDelCampus::class,
    'IndicadoresFinancieros' => App\Services\Plataforma\IndicadoresFinancieros::class,
    'FeriadosOficiales'      => App\Services\Plataforma\FeriadosOficiales::class,
] as $nombre => $clase) {
    $fuente = file_get_contents((new ReflectionClass($clase))->getFileName());
    vale("$nombre ya no arma su propio almacén", ! str_contains($fuente, 'Cache::build'));
    vale("$nombre pasa por CacheExterno", str_contains($fuente, 'CacheExterno::recordar'));
}

echo "\n== El clima de verdad, dentro del tenant ==\n";

App\Models\Tenant::find('demo')->run(function () {
    global $ok, $mal;

    $usuario = App\Models\Identidad\Usuario::where('email', 'alumno.demo.2@escuela.mx')->first();
    vale('hay usuario de prueba', $usuario !== null);
    if ($usuario === null) { return; }

    $servicio = app(App\Services\Plataforma\ClimaDelCampus::class);

    $clima = $servicio->para($usuario);
    vale('el clima responde', is_array($clima) && isset($clima['temperatura'], $clima['lugar']));

    if (is_array($clima)) {
        echo "         → {$clima['lugar']}: {$clima['temperatura']}° {$clima['condicion']} ({$clima['actualizado']})\n";

        // Segunda llamada: debe salir de la caché, no de la red.
        $t = microtime(true);
        $otra = $servicio->para($usuario);
        $ms = (microtime(true) - $t) * 1000;
        vale(sprintf('la segunda sale de caché (%.1f ms)', $ms), $ms < 60 && $otra == $clima);
    }
});

echo "\n$ok en verde".($mal ? ", $mal EN ROJO" : '')."\n";
exit($mal ? 1 : 0);
