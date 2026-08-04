<?php

declare(strict_types=1);

namespace App\Services\Encuestas;

use App\Enums\TipoPregunta;
use App\Models\Encuestas\AplicacionEncuesta;
use App\Models\Encuestas\Pregunta;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Cómo cambió de un ciclo al siguiente.
 *
 * ── Por qué un promedio suelto no basta ────────────────────────────────────
 * «4.1 sobre 5» no dice si la escuela va bien o mal: dice que va en 4.1. Lo que
 * permite decidir es la comparación —subió, bajó, sigue igual—, y por eso el
 * módulo separa el instrumento de su aplicación: para que el 4.1 de este
 * semestre y el 3.8 del anterior sean del MISMO cuestionario y la diferencia
 * signifique algo.
 *
 * ── Se comparan preguntas equivalentes, no posiciones ──────────────────────
 * Cada aplicación tiene su propia copia de las preguntas, con ids distintos,
 * así que se emparejan por TEXTO. Comparar por posición sería comparar la
 * pregunta 3 de un semestre con la 3 del otro aunque alguien haya insertado una
 * en medio, y ahí el número resultante no significaría nada.
 *
 * Lo que sólo existe en una de las dos se informa aparte en vez de esconderse:
 * es la explicación de por qué la comparación no cubre todo.
 */
class ComparaAplicaciones
{
    /**
     * @param  Collection<int, AplicacionEncuesta>  $aplicaciones  En orden cronológico.
     * @return array<string, mixed>
     */
    public function de(Collection $aplicaciones): array
    {
        // Por elemento y no con `loadMissing` sobre la colección: eso último
        // sólo existe en la colección de Eloquent, y aquí puede llegar una
        // colección normal —de una prueba, o de un filtro en memoria—.
        $aplicaciones->each(fn (AplicacionEncuesta $a) => $a->loadMissing('encuesta.preguntas'));

        $columnas = $aplicaciones->map(fn (AplicacionEncuesta $a) => [
            'id' => $a->id,
            'titulo' => $a->titulo,
            'respuestas' => $a->respuestas()->count(),
            'cerrada_en' => $a->cierra_en?->format('Y-m-d'),
        ])->values()->all();

        $filas = [];

        foreach ($this->preguntasComparables($aplicaciones) as $texto => $porAplicacion) {
            $valores = [];

            foreach ($aplicaciones as $aplicacion) {
                $preguntaId = $porAplicacion[$aplicacion->id] ?? null;

                $valores[] = $preguntaId === null
                    // Null y no cero: la pregunta no estaba, que es distinto de
                    // haber salido mal. Un cero aquí inventaría una caída.
                    ? null
                    : $this->promedio($aplicacion->id, $preguntaId);
            }

            $filas[] = [
                'pregunta' => $texto,
                'valores' => $valores,
                'variacion' => $this->variacion($valores),
                'completa' => ! in_array(null, $valores, true),
            ];
        }

        return [
            'aplicaciones' => $columnas,
            'preguntas' => $filas,
            'general' => $this->general($aplicaciones),
        ];
    }

    /**
     * Las preguntas promediables de todas las aplicaciones, emparejadas por su
     * texto.
     *
     * @param  Collection<int, AplicacionEncuesta>  $aplicaciones
     * @return array<string, array<int, int>>  texto => [aplicacion_id => pregunta_id]
     */
    private function preguntasComparables(Collection $aplicaciones): array
    {
        $mapa = [];

        foreach ($aplicaciones as $aplicacion) {
            foreach ($aplicacion->encuesta->preguntas as $pregunta) {
                if (! $pregunta->tipo->esNumerica()) {
                    continue;
                }

                // Normalizado: un espacio de más o una mayúscula distinta no
                // pueden partir en dos lo que es la misma pregunta.
                $clave = $this->normalizar($pregunta->texto);

                $mapa[$clave] ??= ['texto' => $pregunta->texto, 'ids' => []];
                $mapa[$clave]['ids'][$aplicacion->id] = $pregunta->id;
            }
        }

        $salida = [];

        foreach ($mapa as $entrada) {
            $salida[$entrada['texto']] = $entrada['ids'];
        }

        return $salida;
    }

    /** El promedio general de cada aplicación, sobre sus preguntas promediables. */
    private function general(Collection $aplicaciones): array
    {
        $valores = $aplicaciones->map(function (AplicacionEncuesta $aplicacion) {
            $preguntas = $aplicacion->encuesta->preguntas
                ->filter(fn (Pregunta $p) => $p->tipo->esNumerica())
                ->pluck('id');

            if ($preguntas->isEmpty()) {
                return null;
            }

            $promedio = DB::table('encuesta_respuesta_items')
                ->join('encuesta_respuestas', 'encuesta_respuestas.id', '=', 'encuesta_respuesta_items.respuesta_id')
                ->where('encuesta_respuestas.aplicacion_id', $aplicacion->id)
                ->whereIn('encuesta_respuesta_items.pregunta_id', $preguntas)
                ->whereNotNull('encuesta_respuesta_items.numero')
                ->avg('encuesta_respuesta_items.numero');

            return $promedio === null ? null : round((float) $promedio, 2);
        })->values()->all();

        return ['valores' => $valores, 'variacion' => $this->variacion($valores)];
    }

    /**
     * Cuánto cambió entre la primera y la última con dato.
     *
     * Se toman los extremos y no la última pareja: comparar contra el semestre
     * inmediato anterior esconde una caída sostenida de tres ciclos, que es
     * justo lo que hay que ver.
     *
     * @param  array<int, float|null>  $valores
     */
    private function variacion(array $valores): ?float
    {
        $conDato = array_values(array_filter($valores, fn ($v) => $v !== null));

        if (count($conDato) < 2) {
            return null;
        }

        return round(end($conDato) - $conDato[0], 2);
    }

    private function promedio(int $aplicacionId, int $preguntaId): ?float
    {
        $promedio = DB::table('encuesta_respuesta_items')
            ->join('encuesta_respuestas', 'encuesta_respuestas.id', '=', 'encuesta_respuesta_items.respuesta_id')
            ->where('encuesta_respuestas.aplicacion_id', $aplicacionId)
            ->where('encuesta_respuesta_items.pregunta_id', $preguntaId)
            ->whereNotNull('encuesta_respuesta_items.numero')
            ->avg('encuesta_respuesta_items.numero');

        return $promedio === null ? null : round((float) $promedio, 2);
    }

    private function normalizar(string $texto): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $texto) ?? $texto));
    }
}
