<?php

declare(strict_types=1);

/*
 * Apagar el módulo `disciplina` tiene que tener un efecto UNIFORME:
 *
 *  - Todo lo que ES disciplina queda cerrado. Incluye `buscar/matriculas`, que
 *    vivía FUERA del grupo `modulo:disciplina` y seguía devolviendo el padrón
 *    de alumnos (nombre, matrícula, programa) con el módulo apagado.
 *  - Lo que NO es disciplina no se cae. `docencia/citas` estaba mal anidado
 *    DENTRO del grupo, así que apagar disciplina tumbaba la agenda del docente.
 *
 * Se comprueba sobre el router real: qué middleware junta cada ruta.
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Route;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$verificaciones = 0;
$fallidas = 0;

function verificar(string $que, bool $ok, string $detalle = ''): void
{
    global $verificaciones, $fallidas;
    $verificaciones++;
    $ok || $fallidas++;
    echo ($ok ? "  \033[32mOK\033[39m   " : "  \033[31mFALLA\033[39m ").$que.($detalle !== '' ? "  [{$detalle}]" : '').PHP_EOL;
}

/** El middleware ya resuelto de la primera ruta que casa el URI exacto. */
function middlewareDe(string $uri): ?string
{
    foreach (Route::getRoutes()->getRoutes() as $ruta) {
        if ($ruta->uri() === $uri) {
            return implode(',', app('router')->gatherRouteMiddleware($ruta));
        }
    }

    return null;
}

$disciplina = 'ModuloEncendido:disciplina';

echo PHP_EOL.'Rutas de disciplina: gateadas por su módulo'.PHP_EOL;

$incidencias = middlewareDe('escolar/incidencias');
verificar('Existe la ruta de incidencias', $incidencias !== null);
verificar('escolar/incidencias va bajo modulo:disciplina (control positivo)',
    $incidencias !== null && str_contains($incidencias, $disciplina));

$buscar = middlewareDe('buscar/matriculas');
verificar('Existe la ruta buscar/matriculas', $buscar !== null);
verificar('buscar/matriculas va bajo modulo:disciplina (era el hueco A)',
    $buscar !== null && str_contains($buscar, $disciplina));

echo PHP_EOL.'Citas familia-docente: NO es disciplina'.PHP_EOL;

$citas = middlewareDe('docencia/citas');
verificar('Existe la ruta docencia/citas', $citas !== null);
verificar('docencia/citas NO va bajo modulo:disciplina (era el hueco B)',
    $citas !== null && ! str_contains($citas, $disciplina));
verificar('docencia/citas sigue exigiendo su permiso',
    $citas !== null && str_contains($citas, 'gestionar-mis-citas'));

echo PHP_EOL.'Resultado: '.($verificaciones - $fallidas).' correctas, '.$fallidas.' fallidas'.PHP_EOL;
