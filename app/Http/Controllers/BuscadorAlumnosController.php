<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Academico\Oferta;
use App\Models\Academico\ProgramaAcademico;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\Identidad\Persona;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Buscar alumnos para dirigirles algo: un aviso, un evento, una encuesta.
 *
 * ── Por qué está en la raíz y no dentro de una sección ─────────────────────
 * Porque lo usan tres pantallas de tres módulos distintos —calendario, avisos y
 * encuestas— con el mismo componente (`SelectorDestinos`). Vivía únicamente
 * dentro del calendario, y las otras dos apuntaban a `/api/buscar/alumnos`, una
 * dirección que NUNCA existió: al escribir en el buscador, `BuscadorRemoto`
 * hacía la petición, recibía 404 y —como sólo tiene `finally`, sin `catch`— la
 * caja se quedaba en blanco sin decir nada. Elegir «alumnos señalados uno por
 * uno» simplemente no funcionaba en avisos ni en encuestas, y parecía que no
 * había resultados.
 *
 * ── Y por qué el permiso es derivado ───────────────────────────────────────
 * La misma puerta la abren tres permisos distintos. Colgarla de uno dejaría
 * fuera a los otros dos oficios; pedirle a la escuela una casilla aparte
 * produciría el clásico 403 sin explicación al armar un rol nuevo. Es la regla
 * que ya siguen `subir-material` y `usar-rubricas`.
 */
class BuscadorAlumnosController extends Controller
{
    public function __invoke(Request $peticion): JsonResponse
    {
        $texto = trim((string) $peticion->query('q', ''));

        // Con una letra la lista sería medio padrón: no ayuda a elegir y cuesta
        // una consulta por cada tecla.
        if (mb_strlen($texto) < 2) {
            return response()->json([]);
        }

        /*
         * Los nombres de tabla se PREGUNTAN a los modelos.
         *
         * La versión anterior de esta consulta —la que vivía en el calendario—
         * unía contra `ofertas`, en plural. La tabla se llama `oferta`, así que
         * la única de las tres pantallas que apuntaba a un endpoint existente
         * tampoco funcionaba: reventaba con «table doesn't exist». Es la trampa
         * que la bitácora ya tenía anotada, y escribir el nombre a mano es lo
         * que la hace posible.
         */
        $matriculas = (new MatriculaOferta)->getTable();
        $personas = (new Persona)->getTable();
        $ofertas = (new Oferta)->getTable();
        $programasAcademicos = (new ProgramaAcademico)->getTable();

        $alumnos = DB::table($matriculas)
            ->join($personas, "{$personas}.id", '=', "{$matriculas}.persona_id")
            ->leftJoin($ofertas, "{$ofertas}.id", '=', "{$matriculas}.oferta_id")
            ->leftJoin($programasAcademicos, "{$programasAcademicos}.id", '=', "{$ofertas}.programa_academico_id")
            ->whereNull("{$matriculas}.deleted_at")
            ->where(function ($donde) use ($texto, $matriculas, $personas) {
                $donde->where("{$matriculas}.matricula", 'like', "%{$texto}%")
                    ->orWhereRaw(
                        "TRIM(CONCAT_WS(' ', {$personas}.nombre, {$personas}.primer_apellido, {$personas}.segundo_apellido)) LIKE ?",
                        ["%{$texto}%"],
                    );
            })
            ->orderBy("{$personas}.primer_apellido")
            ->limit(20)
            /*
             * El destino se guarda contra la PERSONA y no contra la matrícula:
             * lo que se le manda es al alumno, aunque curse dos programas académicos. Por
             * eso además se deduplica por id — si no, quien estudia dos
             * programas aparecería dos veces en la lista para elegir.
             */
            ->get([
                "{$personas}.id",
                "{$matriculas}.matricula",
                "{$programasAcademicos}.nombre as programa_academico",
                DB::raw("TRIM(CONCAT_WS(' ', {$personas}.nombre, {$personas}.primer_apellido, {$personas}.segundo_apellido)) AS nombre"),
            ]);

        return response()->json($alumnos->unique('id')->values());
    }
}
