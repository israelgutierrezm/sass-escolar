<?php

declare(strict_types=1);

namespace App\Services\Encuestas;

use App\Enums\TipoPregunta;
use App\Models\Encuestas\AplicacionEncuesta;
use App\Models\Encuestas\Pregunta;
use App\Models\Encuestas\Respuesta;
use App\Models\Encuestas\Sujeto;
use Illuminate\Support\Facades\DB;

/**
 * Lo que la escuela puede decidir con lo que se contestó.
 *
 * ── El umbral de anonimato ─────────────────────────────────────────────────
 * Con tres respuestas o menos, publicar el desglose de un docente equivale a
 * señalar a quien contestó: en un grupo de cinco, todos saben quién falta. Por
 * eso los resultados por sujeto se ocultan bajo ese mínimo. Prometer anonimato
 * y luego enseñar un promedio de dos respuestas es peor que no prometerlo: la
 * siguiente encuesta ya nadie la contesta con sinceridad.
 *
 * ── Qué se agrega y qué no ─────────────────────────────────────────────────
 * Las escalas y los números se promedian —son los que ordenan y comparan—; las
 * opciones se cuentan y se reparten en porcentajes; las abiertas se listan tal
 * cual. Promediar opciones daría un número sin significado, y contar textos no
 * dice nada de lo que dicen.
 */
class ResultadosDeEncuesta
{
    /**
     * Por debajo de esto no se muestra el detalle de un sujeto.
     *
     * Cuatro y no dos: con dos, quien contestó reconoce su propia respuesta en
     * el promedio y sabe qué dijo el otro.
     */
    public const MINIMO_PARA_MOSTRAR = 4;

    /**
     * El resumen de la aplicación: participación y resultados por pregunta.
     *
     * @return array<string, mixed>
     */
    public function de(AplicacionEncuesta $aplicacion, ?int $sujetoId = null): array
    {
        $aplicacion->loadMissing('encuesta.preguntas.opciones');

        $respuestas = $this->idsDeRespuestas($aplicacion, $sujetoId);
        $total = count($respuestas);

        return [
            'respuestas' => $total,
            'esperadas' => $this->esperadas($aplicacion, $sujetoId),
            'anonima' => $aplicacion->anonima,
            // Se dice por qué está oculto, no sólo que lo está: sin el motivo,
            // parece un error del sistema.
            'oculto' => $sujetoId !== null && $total < self::MINIMO_PARA_MOSTRAR,
            'minimo' => self::MINIMO_PARA_MOSTRAR,
            'preguntas' => $total === 0 ? [] : $aplicacion->encuesta->preguntas
                ->map(fn (Pregunta $p) => $this->dePregunta($p, $respuestas))
                ->all(),
        ];
    }

    /**
     * El tablero de la evaluación docente: cada sujeto con su promedio.
     *
     * Ordenado de menor a mayor a propósito. Un tablero de evaluación docente
     * se mira para actuar, y sobre quien sale bien no hay nada que hacer: lo
     * que hay que ver primero es dónde hay un problema.
     *
     * @return array<int, array<string, mixed>>
     */
    public function porSujeto(AplicacionEncuesta $aplicacion): array
    {
        $promediables = $this->preguntasPromediables($aplicacion);

        return $aplicacion->sujetos()
            ->with(['persona', 'materia.planMateria.asignatura', 'materia.grupo'])
            ->get()
            ->map(function (Sujeto $sujeto) use ($aplicacion, $promediables) {
                $respuestas = $this->idsDeRespuestas($aplicacion, $sujeto->id);
                $total = count($respuestas);

                return [
                    'sujeto_id' => $sujeto->id,
                    'docente' => $sujeto->persona?->nombreCompleto() ?? 'Sin nombre',
                    'materia' => $sujeto->materia?->planMateria?->asignatura?->nombre,
                    'grupo' => $sujeto->materia?->grupo?->clave,
                    'papel' => $sujeto->papel,
                    'respuestas' => $total,
                    'esperadas' => $this->esperadas($aplicacion, $sujeto->id),
                    // Null cuando no alcanza el mínimo: es lo que impide que el
                    // tablero delate a quien contestó en un grupo pequeño.
                    'promedio' => $total < self::MINIMO_PARA_MOSTRAR
                        ? null
                        : $this->promedioDe($respuestas, $promediables),
                ];
            })
            ->sortBy(fn (array $f) => $f['promedio'] ?? PHP_INT_MAX)
            ->values()
            ->all();
    }

    /**
     * El resultado de UNA pregunta.
     *
     * @param  array<int, int>  $respuestas
     * @return array<string, mixed>
     */
    private function dePregunta(Pregunta $pregunta, array $respuestas): array
    {
        $base = [
            'id' => $pregunta->id,
            'texto' => $pregunta->texto,
            'tipo' => $pregunta->tipo->value,
            'tipo_etiqueta' => $pregunta->tipo->etiqueta(),
        ];

        if ($pregunta->tipo === TipoPregunta::Abierta) {
            return [...$base, 'textos' => $this->textos($pregunta->id, $respuestas)];
        }

        if ($pregunta->tipo->esNumerica()) {
            return [...$base, ...$this->numeros($pregunta, $respuestas)];
        }

        return [...$base, 'opciones' => $this->conteoDeOpciones($pregunta, $respuestas)];
    }

    /**
     * Promedio, mínimo y máximo de una escala o un número.
     *
     * @param  array<int, int>  $respuestas
     * @return array<string, mixed>
     */
    private function numeros(Pregunta $pregunta, array $respuestas): array
    {
        $fila = DB::table('encuesta_respuesta_items')
            ->where('pregunta_id', $pregunta->id)
            ->whereIn('respuesta_id', $respuestas)
            ->whereNotNull('numero')
            ->selectRaw('AVG(numero) as promedio, MIN(numero) as minimo, MAX(numero) as maximo, COUNT(*) as total')
            ->first();

        // La distribución, para ver si un 3.5 es «todos regulares» o «la mitad
        // encantada y la mitad furiosa», que piden cosas distintas.
        $distribucion = DB::table('encuesta_respuesta_items')
            ->where('pregunta_id', $pregunta->id)
            ->whereIn('respuesta_id', $respuestas)
            ->whereNotNull('numero')
            ->groupBy('numero')
            ->orderBy('numero')
            ->selectRaw('numero, COUNT(*) as total')
            ->get()
            ->map(fn ($f) => ['valor' => (float) $f->numero, 'total' => (int) $f->total])
            ->all();

        return [
            'promedio' => $fila?->promedio === null ? null : round((float) $fila->promedio, 2),
            'minimo' => $fila?->minimo === null ? null : (float) $fila->minimo,
            'maximo' => $fila?->maximo === null ? null : (float) $fila->maximo,
            'contestadas' => (int) ($fila?->total ?? 0),
            'escala_maxima' => $pregunta->escalaMaxima(),
            'distribucion' => $distribucion,
        ];
    }

    /**
     * Cuántos marcaron cada opción, con su porcentaje.
     *
     * El porcentaje se calcula sobre quienes CONTESTARON esa pregunta, no sobre
     * el total de respuestas: en una pregunta opcional que la mitad saltó, el
     * otro denominador diría que ninguna opción llegó al 50%.
     *
     * @param  array<int, int>  $respuestas
     * @return array<int, array<string, mixed>>
     */
    private function conteoDeOpciones(Pregunta $pregunta, array $respuestas): array
    {
        $conteos = DB::table('encuesta_respuesta_items')
            ->where('pregunta_id', $pregunta->id)
            ->whereIn('respuesta_id', $respuestas)
            ->whereNotNull('opcion_id')
            ->groupBy('opcion_id')
            ->selectRaw('opcion_id, COUNT(*) as total')
            ->pluck('total', 'opcion_id');

        $quienesContestaron = DB::table('encuesta_respuesta_items')
            ->where('pregunta_id', $pregunta->id)
            ->whereIn('respuesta_id', $respuestas)
            ->distinct()
            ->count('respuesta_id');

        return $pregunta->opciones->map(function ($opcion) use ($conteos, $quienesContestaron) {
            $total = (int) ($conteos[$opcion->id] ?? 0);

            return [
                'texto' => $opcion->texto,
                'total' => $total,
                'porcentaje' => $quienesContestaron === 0 ? 0 : round($total * 100 / $quienesContestaron),
            ];
        })->all();
    }

    /**
     * Las respuestas abiertas, tal cual.
     *
     * @param  array<int, int>  $respuestas
     * @return array<int, string>
     */
    private function textos(int $preguntaId, array $respuestas): array
    {
        return DB::table('encuesta_respuesta_items')
            ->where('pregunta_id', $preguntaId)
            ->whereIn('respuesta_id', $respuestas)
            ->whereNotNull('texto')
            // Sin ordenar por id: el orden de captura junto al de la lista de
            // participantes acabaría delatando quién dijo qué.
            ->inRandomOrder()
            ->pluck('texto')
            ->all();
    }

    /**
     * El promedio general de un sujeto sobre sus preguntas promediables.
     *
     * @param  array<int, int>  $respuestas
     * @param  array<int, int>  $preguntas
     */
    private function promedioDe(array $respuestas, array $preguntas): ?float
    {
        if ($preguntas === [] || $respuestas === []) {
            return null;
        }

        $promedio = DB::table('encuesta_respuesta_items')
            ->whereIn('pregunta_id', $preguntas)
            ->whereIn('respuesta_id', $respuestas)
            ->whereNotNull('numero')
            ->avg('numero');

        return $promedio === null ? null : round((float) $promedio, 2);
    }

    /** @return array<int, int> */
    private function preguntasPromediables(AplicacionEncuesta $aplicacion): array
    {
        return $aplicacion->encuesta->preguntas
            ->filter(fn (Pregunta $p) => $p->tipo->esNumerica())
            ->pluck('id')
            ->all();
    }

    /** @return array<int, int> */
    private function idsDeRespuestas(AplicacionEncuesta $aplicacion, ?int $sujetoId): array
    {
        return Respuesta::query()
            ->where('aplicacion_id', $aplicacion->id)
            ->when($sujetoId !== null, fn ($q) => $q->where('sujeto_id', $sujetoId))
            ->pluck('id')
            ->all();
    }

    /**
     * A cuántos se les pidió.
     *
     * Es el denominador de la participación, y sin él «treinta respuestas» no
     * dice si la encuesta funcionó o fracasó.
     */
    private function esperadas(AplicacionEncuesta $aplicacion, ?int $sujetoId): int
    {
        if ($sujetoId === null) {
            return $aplicacion->participaciones()->count();
        }

        // Para un sujeto: los alumnos inscritos en esa materia.
        $sujeto = Sujeto::find($sujetoId);

        if ($sujeto?->asignatura_grupo_id === null) {
            return 0;
        }

        return DB::table('inscripcion')
            ->where('asignatura_grupo_id', $sujeto->asignatura_grupo_id)
            ->count();
    }
}
