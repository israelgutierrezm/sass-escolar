<?php

declare(strict_types=1);

namespace App\Permanencia;

/**
 * Lo que un proveedor contesta sobre un alumno y una métrica.
 *
 * ── Tres resultados y no dos ───────────────────────────────────────────────
 * `valor` puede ser un número o NULL, y NULL **no es cero**: es «aquí no hay
 * con qué contestar». La distinción es la columna vertebral del módulo, porque
 * el demo la enseña sin ambigüedad —`asistencia_clase` tiene 8 filas para 17
 * inscripciones—: sin ella, a quien no tiene lista pasada se le calcularía
 * «0 % de asistencia» y se le levantaría una alerta que no significa nada.
 *
 * ── `cobertura` es cuántos DATOS respaldan el número ───────────────────────
 * No es la confianza estadística ni una puntuación: es un conteo de lo que se
 * midió —sesiones con lista pasada, actividades ya vencidas, materias
 * asentadas—. La regla declara cuántos exige, y bajo ese mínimo la evaluación
 * NO dispara ni deja de disparar: sale `sin_datos`.
 *
 * ── Y `evidencia` es lo que hace explicable la alerta ──────────────────────
 * Un puntaje sin explicación no se puede validar, ni descartar, ni discutir con
 * el alumno. Aquí viaja lo que produjo el número —los conteos, el periodo, la
 * materia— y es lo que la alerta congela: la evidencia se guarda con la alerta,
 * no se recalcula al mirarla, porque el dato de hoy ya no es el de entonces.
 */
final readonly class Medicion
{
    /**
     * @param  float|null  $valor  el número medido; NULL = no hay con qué contestar
     * @param  int  $cobertura  cuántos datos lo respaldan
     * @param  array<string, mixed>  $evidencia  lo que produjo el número
     * @param  int|null  $asignaturaGrupoId  para las métricas POR MATERIA
     */
    public function __construct(
        public ?float $valor,
        public int $cobertura,
        public array $evidencia = [],
        public ?int $asignaturaGrupoId = null,
    ) {}

    /** No hay con qué contestar. Distinto de un cero. */
    public static function sinDatos(array $evidencia = [], ?int $asignaturaGrupoId = null): self
    {
        return new self(null, 0, $evidencia, $asignaturaGrupoId);
    }

    public function hayDato(): bool
    {
        return $this->valor !== null;
    }
}
