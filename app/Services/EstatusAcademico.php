<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Regla ÚNICA calificación → estatus académico de una materia.
 *
 * El mínimo aprobatorio lo define el plan (`calificacion_minima_aprobatoria`).
 * A partir de la calificación, el estatus se deduce solo y, salvo un caso, no
 * se elige a mano:
 *
 *  - `>= mínimo`        → **aprobada** (forzado, sin opción a cambiar).
 *  - `> 0` y `< mínimo` → **reprobada** (forzado).
 *  - `== 0`             → **reprobada** o **no presentó** (lo decide quien
 *                          captura: un cero puede ser un examen tronado o una
 *                          ausencia, y solo una persona sabe cuál).
 *  - sin calificación   → libre (p. ej. «en curso», o exento/acreditado sin
 *                          número): la regla no fuerza nada.
 *
 * Es la misma frontera de aprobado que usa el cierre de actas
 * (`CalculadoraCalificacion`: final >= mínimo); aquí se amplía con el matiz
 * reprobada/no-presentó que el historial académico manual sí necesita. Debe ser la única
 * fuente de esta lógica: cualquier pantalla que derive estatus de una
 * calificación pasa por aquí.
 */
class EstatusAcademico
{
    /**
     * @return array{claves: array<int, string>, sugerido: string, bloqueado: bool}
     */
    public function resolver(?float $calificacion, float $minimaAprobatoria): array
    {
        if ($calificacion === null) {
            return ['claves' => ['en_curso', 'aprobada', 'reprobada', 'no_presento'], 'sugerido' => 'en_curso', 'bloqueado' => false];
        }

        if ($calificacion >= $minimaAprobatoria) {
            return ['claves' => ['aprobada'], 'sugerido' => 'aprobada', 'bloqueado' => true];
        }

        if ($calificacion > 0) {
            return ['claves' => ['reprobada'], 'sugerido' => 'reprobada', 'bloqueado' => true];
        }

        return ['claves' => ['reprobada', 'no_presento'], 'sugerido' => 'reprobada', 'bloqueado' => false];
    }

    /** ¿Ese estatus (por clave) es admisible para esa calificación bajo la regla? */
    public function permite(?float $calificacion, float $minimaAprobatoria, ?string $claveEstatus): bool
    {
        return $claveEstatus !== null
            && in_array($claveEstatus, $this->resolver($calificacion, $minimaAprobatoria)['claves'], true);
    }
}
