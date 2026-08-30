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
 * Buscar una MATRÍCULA para registrarle algo suyo: una incidencia, una sanción.
 *
 * ── Devuelve matrículas, no personas ──────────────────────────────────────
 * A diferencia de `BuscadorAlumnosController` —que deduplica por persona porque
 * ahí lo que se manda es al alumno, curse una o dos programas académicos—, aquí cada
 * matrícula es un destino DISTINTO: la conducta cuelga de la matrícula, y quien
 * estudia dos programas puede tener una incidencia en uno y no en el otro. Por
 * eso NO se deduplica y se devuelve `matricula_oferta_id`, no `persona_id`.
 */
class BuscadorMatriculasController extends Controller
{
    public function __invoke(Request $peticion): JsonResponse
    {
        $texto = trim((string) $peticion->query('q', ''));

        // Con una letra sería medio padrón: no ayuda y cuesta una consulta por
        // tecla.
        if (mb_strlen($texto) < 2) {
            return response()->json([]);
        }

        // Los nombres de tabla se PREGUNTAN a los modelos: `oferta` es singular
        // y ya mordió en el otro buscador.
        $matriculas = (new MatriculaOferta)->getTable();
        $personas = (new Persona)->getTable();
        $ofertas = (new Oferta)->getTable();
        $programasAcademicos = (new ProgramaAcademico)->getTable();

        $filas = DB::table($matriculas)
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
            ->get([
                "{$matriculas}.id",
                "{$matriculas}.matricula",
                "{$programasAcademicos}.nombre as programa_academico",
                DB::raw("TRIM(CONCAT_WS(' ', {$personas}.nombre, {$personas}.primer_apellido, {$personas}.segundo_apellido)) AS nombre"),
            ]);

        return response()->json($filas);
    }
}
