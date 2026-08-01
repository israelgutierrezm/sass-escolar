<?php

declare(strict_types=1);

namespace App\Services\Lms;

use App\Enums\TipoReactivo;
use App\Models\Lms\Reactivo;

/**
 * Califica UN reactivo contra lo que el alumno contestó.
 *
 * Vive aparte del examen y no toca la base: entra un reactivo y una respuesta,
 * sale una fracción de 0 a 1. Así cada tipo se puede probar solo, que es lo
 * único que hace confiable a un autocalificador —doce reglas de comparación son
 * doce sitios donde equivocarse en silencio, y equivocarse aquí es poner mal
 * una calificación real—.
 *
 * Devuelve FRACCIÓN y no puntos porque el peso lo pone el examen: la misma
 * pregunta vale 1 en un parcial y 3 en el extraordinario.
 *
 * `null` significa «esto no lo califico yo»: lo abierto y los archivos esperan
 * al docente.
 */
class CalificadorReactivo
{
    public function fraccion(Reactivo $reactivo, mixed $respuesta): ?float
    {
        if (! $reactivo->tipo->autocalificable()) {
            return null;
        }

        return match ($reactivo->tipo) {
            TipoReactivo::OpcionUnica,
            TipoReactivo::VerdaderoFalso => $this->unaOpcion($reactivo, $respuesta),
            TipoReactivo::OpcionMultiple => $this->variasOpciones($reactivo, $respuesta),
            TipoReactivo::RespuestaCorta => $this->respuestaCorta($reactivo, $respuesta),
            TipoReactivo::Numerica => $this->numerica($reactivo, $respuesta),
            TipoReactivo::Completar => $this->completar($reactivo, $respuesta),
            TipoReactivo::RelacionColumnas,
            TipoReactivo::Clasificar => $this->emparejar($reactivo, $respuesta),
            TipoReactivo::Ordenamiento => $this->ordenamiento($reactivo, $respuesta),
            TipoReactivo::Hotspot => $this->hotspot($reactivo, $respuesta),
            default => null,
        };
    }

    /** Acertó si eligió la única opción correcta. */
    private function unaOpcion(Reactivo $reactivo, mixed $respuesta): float
    {
        $correcta = $reactivo->opciones->firstWhere('correcta', true);

        return $correcta !== null && (int) $respuesta === (int) $correcta->id ? 1.0 : 0.0;
    }

    /**
     * Todo o nada: hay que marcar EXACTAMENTE el conjunto correcto.
     *
     * Se descartó el crédito parcial a propósito. Con puntos por cada acierto,
     * marcar todas las opciones garantizaría nota; y restando por cada error se
     * castiga distinto según cuántas opciones tenga el reactivo, que es una
     * arbitrariedad difícil de explicarle a un alumno.
     */
    private function variasOpciones(Reactivo $reactivo, mixed $respuesta): float
    {
        $correctas = $reactivo->opciones->where('correcta', true)->pluck('id')
            ->map(fn ($id) => (int) $id)->sort()->values()->all();

        $elegidas = collect(is_array($respuesta) ? $respuesta : [])
            ->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();

        return $correctas === $elegidas ? 1.0 : 0.0;
    }

    /**
     * Se compara contra una lista de respuestas aceptadas, normalizando
     * mayúsculas, acentos y espacios de más: quien escribe «Mexico» sabe lo
     * mismo que quien escribe «México», y castigar el acento mide otra cosa.
     */
    private function respuestaCorta(Reactivo $reactivo, mixed $respuesta): float
    {
        $aceptadas = collect($reactivo->respuesta['aceptadas'] ?? [])
            ->map(fn ($t) => $this->normalizar((string) $t));

        return $aceptadas->contains($this->normalizar((string) $respuesta)) ? 1.0 : 0.0;
    }

    /** Un número, con la tolerancia que el docente haya definido. */
    private function numerica(Reactivo $reactivo, mixed $respuesta): float
    {
        if (! is_numeric($respuesta)) {
            return 0.0;
        }

        $esperado = (float) ($reactivo->respuesta['valor'] ?? 0);
        $tolerancia = abs((float) ($reactivo->respuesta['tolerancia'] ?? 0));

        return abs((float) $respuesta - $esperado) <= $tolerancia ? 1.0 : 0.0;
    }

    /**
     * Cada hueco cuenta por separado: acertar tres de cuatro da 0.75. Aquí sí
     * hay crédito parcial porque cada hueco es una pregunta independiente, no
     * una elección dentro de un mismo conjunto.
     */
    private function completar(Reactivo $reactivo, mixed $respuesta): float
    {
        $huecos = $reactivo->respuesta['huecos'] ?? [];

        if ($huecos === []) {
            return 0.0;
        }

        $dadas = is_array($respuesta) ? $respuesta : [];
        $aciertos = 0;

        foreach ($huecos as $i => $aceptadas) {
            $dada = $this->normalizar((string) ($dadas[$i] ?? ''));

            if ($dada !== '' && collect($aceptadas)->map(fn ($t) => $this->normalizar((string) $t))->contains($dada)) {
                $aciertos++;
            }
        }

        return $aciertos / count($huecos);
    }

    /** Relación de columnas y clasificar: crédito por cada par bien puesto. */
    private function emparejar(Reactivo $reactivo, mixed $respuesta): float
    {
        $esperado = $reactivo->opciones->pluck('pareja', 'id')->filter();

        if ($esperado->isEmpty()) {
            return 0.0;
        }

        $dadas = is_array($respuesta) ? $respuesta : [];
        $aciertos = 0;

        foreach ($esperado as $opcionId => $pareja) {
            if ($this->normalizar((string) ($dadas[$opcionId] ?? '')) === $this->normalizar((string) $pareja)) {
                $aciertos++;
            }
        }

        return $aciertos / $esperado->count();
    }

    /**
     * Ordenar es todo o nada: una secuencia con dos elementos cambiados de
     * lugar no está «casi bien», está mal. El orden correcto sale del campo
     * `orden` de las opciones.
     */
    private function ordenamiento(Reactivo $reactivo, mixed $respuesta): float
    {
        $correcto = $reactivo->opciones->sortBy('orden')->pluck('id')
            ->map(fn ($id) => (int) $id)->values()->all();

        $dado = collect(is_array($respuesta) ? $respuesta : [])
            ->map(fn ($id) => (int) $id)->values()->all();

        return $correcto === $dado ? 1.0 : 0.0;
    }

    /**
     * Señalar en una imagen. La zona es un rectángulo en coordenadas
     * NORMALIZADAS (0..1) para que valga igual en cualquier tamaño de pantalla:
     * guardar píxeles ataría el acierto al monitor del alumno.
     */
    private function hotspot(Reactivo $reactivo, mixed $respuesta): float
    {
        $zona = $reactivo->respuesta['zona'] ?? null;

        if (! is_array($zona) || ! is_array($respuesta)) {
            return 0.0;
        }

        $x = (float) ($respuesta['x'] ?? -1);
        $y = (float) ($respuesta['y'] ?? -1);

        $dentro = $x >= (float) ($zona['x'] ?? 0)
            && $x <= (float) ($zona['x'] ?? 0) + (float) ($zona['w'] ?? 0)
            && $y >= (float) ($zona['y'] ?? 0)
            && $y <= (float) ($zona['y'] ?? 0) + (float) ($zona['h'] ?? 0);

        return $dentro ? 1.0 : 0.0;
    }

    /** Minúsculas, sin acentos y sin espacios de sobra. */
    private function normalizar(string $texto): string
    {
        $sinAcentos = preg_replace(
            '/[\x{0300}-\x{036f}]/u',
            '',
            \Normalizer::normalize($texto, \Normalizer::FORM_D) ?: $texto,
        );

        return trim(preg_replace('/\s+/u', ' ', mb_strtolower($sinAcentos ?? $texto)) ?? '');
    }
}
