<?php

/**
 * Qué reactivos le tocan a un intento, y EN QUÉ ORDEN. Con rollback.
 *
 * Se corre con `php scripts/prueba-orden-examen.php` desde la raíz.
 *
 * ── Lo que hay que vigilar ─────────────────────────────────────────────────
 * Elegir un subconjunto y barajar el orden son DOS decisiones distintas, y
 * estaban en la misma condición: bastaba con fijar «presentar N» para que el
 * orden saliera al azar aunque el docente hubiera APAGADO el barajado.
 *
 *  1. Sin barajar, el orden es el que el docente les dio, presente todos o un
 *     subconjunto. Un examen cuyas preguntas se apoyan unas en otras se
 *     desordenaba sin que nadie lo pidiera.
 *  2. Para quedarse con N de M sí hace falta azar: sin él, todos verían
 *     exactamente los mismos N y el tope no serviría de nada.
 *  3. Barajando, el orden cambia de verdad.
 */

use App\Enums\TipoActividad;
use App\Models\ControlEscolar\Inscripcion;
use App\Models\Lms\Actividad;
use App\Models\Lms\Curso;
use App\Models\Lms\Examen;
use App\Models\Tenant;
use App\Services\Lms\AplicadorExamen;
use Illuminate\Contracts\Console\Kernel;
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

/** El método es privado: se prueba lo que decide, no cómo se llama. */
function sortear(Examen $examen): array
{
    $m = new ReflectionMethod(AplicadorExamen::class, 'sortearReactivos');
    $m->setAccessible(true);

    return $m->invoke(app(AplicadorExamen::class), $examen);
}

$db->beginTransaction();

try {
    /*
     * El escenario se CONSTRUYE entero: el único examen del demo cuelga de un
     * curso huérfano —su `asignatura_grupo` ya no existe— y no se puede
     * alcanzar. Una prueba que se salta la comprobación cuando no encuentra el
     * caso es una prueba que se apaga sola.
     */
    $inscripcion = Inscripcion::query()->whereNotNull('asignatura_grupo_id')->firstOrFail();

    $curso = Curso::query()->where('asignatura_grupo_id', $inscripcion->asignatura_grupo_id)->first()
        ?? Curso::create([
            'asignatura_grupo_id' => $inscripcion->asignatura_grupo_id,
            'titulo' => 'Curso de prueba',
        ]);

    $actividad = Actividad::create([
        'curso_id' => $curso->id,
        'tipo' => TipoActividad::Examen,
        'titulo' => 'Examen de orden',
        'puntos' => 30,
        'publicada' => true,
    ]);

    $examen = Examen::create([
        'actividad_id' => $actividad->id,
        'intentos_permitidos' => 1,
        'reactivos_a_presentar' => null,
        'barajar_reactivos' => false,
        'barajar_opciones' => false,
        'permite_captura' => true,
        'una_por_pagina' => false,
        'intento_que_cuenta' => 'ultimo',
        'mostrar_resultado' => 'al_entregar',
    ]);

    // Seis reactivos con un orden EXPLÍCITO, que es lo que hay que respetar.
    for ($i = 0; $i < 6; $i++) {
        $r = $db->table('reactivos')->insertGetId([
            'curso_id' => $curso->id,
            'tipo' => 'opcion_unica',
            'enunciado' => "Pregunta {$i}",
            'puntos' => 5,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $db->table('examen_reactivo')->insert([
            'examen_id' => $examen->id, 'reactivo_id' => $r, 'puntos' => 5, 'orden' => $i,
        ]);
    }

    $configurado = $examen->fresh()->reactivos()->pluck('reactivos.id')->map(fn ($x) => (int) $x)->all();

    verificar('El examen queda con seis reactivos ordenados', count($configurado) === 6);

    echo PHP_EOL.'1. Sin barajar: manda el orden del docente'.PHP_EOL;

    $igualSiempre = true;

    for ($i = 0; $i < 12; $i++) {
        $igualSiempre = $igualSiempre && sortear($examen->fresh()) === $configurado;
    }

    verificar('Sin tope, doce intentos dan siempre el mismo orden', $igualSiempre);

    // Y con tope al TOTAL: es el caso que más obviamente estaba mal, porque no
    // hay nada que elegir y aun así se barajaba.
    $examen->update(['reactivos_a_presentar' => 6]);
    $igualSiempre = true;

    for ($i = 0; $i < 12; $i++) {
        $igualSiempre = $igualSiempre && sortear($examen->fresh()) === $configurado;
    }

    verificar('Presentando TODOS, tampoco se baraja', $igualSiempre);

    echo PHP_EOL.'2. Un subconjunto se elige al azar, pero se ordena como el docente'.PHP_EOL;

    $examen->update(['reactivos_a_presentar' => 3]);

    $subconjuntos = [];
    $siempreTres = true;
    $siempreOrdenados = true;

    for ($i = 0; $i < 30; $i++) {
        $r = sortear($examen->fresh());
        $subconjuntos[implode(',', $r)] = true;
        $siempreTres = $siempreTres && count($r) === 3;

        // Ordenado = sus posiciones en el configurado van en aumento.
        $pos = array_map(fn (int $id) => array_search($id, $configurado, true), $r);
        $creciente = $pos;
        sort($creciente);
        $siempreOrdenados = $siempreOrdenados && $pos === $creciente;
    }

    verificar('Siempre son tres', $siempreTres);
    verificar('Y siempre en el orden configurado', $siempreOrdenados);
    verificar('Pero CUÁLES tres varía: si no, el tope no serviría de nada',
        count($subconjuntos) > 1, count($subconjuntos).' subconjuntos distintos');

    echo PHP_EOL.'3. Barajando sí cambia el orden'.PHP_EOL;

    $examen->update(['barajar_reactivos' => true, 'reactivos_a_presentar' => 6]);

    $ordenes = [];

    for ($i = 0; $i < 30; $i++) {
        $ordenes[implode(',', sortear($examen->fresh()))] = true;
    }

    verificar('Treinta intentos dan más de un orden', count($ordenes) > 1, count($ordenes).' distintos');
} finally {
    $db->rollBack();
    echo PHP_EOL.'-- rollback aplicado, la base queda como estaba --'.PHP_EOL;
}

echo PHP_EOL.'Resultado: '.($verificaciones - $fallidas)." correctas, {$fallidas} fallidas".PHP_EOL;

exit($fallidas === 0 ? 0 : 1);
