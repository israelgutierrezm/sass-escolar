<?php

declare(strict_types=1);

namespace App\Http\Controllers\Permanencia;

use App\Http\Controllers\Controller;
use App\Services\Permanencia\IndicadoresDePermanencia;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * El tablero: las cifras del módulo, y lo que hace falta para no leerlas mal.
 *
 * ── Su permiso es PROPIO, y no el de la bandeja ───────────────────────────
 * Quien mira indicadores no atiende a nadie —dirección, planeación, quien
 * prepara un informe— y quien acompaña casos todos los días no tiene por qué
 * ver las cifras agregadas de la escuela. Con un permiso compartido, dar acceso
 * al tablero obligaría a dar acceso a los expedientes.
 *
 * ── Y aquí NO hay un solo nombre ──────────────────────────────────────────
 * Son conteos. Los nombres viven en la bandeja y en los casos, cada uno con su
 * permiso y su alcance; el servicio no los devuelve nunca, y los desgloses con
 * muy pocos alumnos se suprimen porque en una escuela chica «2 casos de esta
 * generación» señala con el dedo.
 */
class TableroPermanenciaController extends Controller
{
    /** Las ventanas que se ofrecen. Un mes, un trimestre, un ciclo. */
    public const VENTANAS = [30, 90, 180];

    public function index(Request $peticion, IndicadoresDePermanencia $indicadores): Response
    {
        $dias = (int) $peticion->integer('dias', IndicadoresDePermanencia::DIAS);

        /*
         * La ventana se valida contra la LISTA, no con un rango: un número
         * cualquiera por la URL daría un tablero que nadie puede reproducir
         * desde la pantalla, y un `dias=100000` recorrería la escuela entera.
         */
        in_array($dias, self::VENTANAS, true) || $dias = IndicadoresDePermanencia::DIAS;

        return Inertia::render('Permanencia/Tablero', [
            'tablero' => $indicadores->tablero($peticion->user(), $dias),
            'ventanas' => self::VENTANAS,
            'ventana' => $dias,
            /*
             * Si esta persona está acotada se DICE, y no como un detalle: sin
             * eso, quien coordina un plantel lee sus cifras creyendo que son las
             * de la escuela — y las compara con las de una junta directiva.
             */
            'acotado' => $peticion->user()?->campusVisibles() !== null,
        ]);
    }
}
