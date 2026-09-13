<?php

declare(strict_types=1);

namespace App\Services\Lms;

use App\Models\Lms\Entrega;

/**
 * La calificación DIRECTA de una entrega (sin rúbrica): la escribe y deja que el
 * componente se recalcule solo.
 *
 * Vivía dentro de `ActividadController::calificar` (la web); la app califica
 * igual, así que la escritura vive aquí. La calificación por RÚBRICA es otra
 * cosa —sale de los niveles elegidos, no se teclea— y ya tiene su servicio
 * (`CalificadorPorRubrica`); quién de los dos aplica lo decide el llamador según
 * la ACTIVIDAD, nunca según lo que traiga la petición.
 */
class CalificacionDeEntrega
{
    public function __construct(private readonly CalculadorComponente $calculador) {}

    public function directa(Entrega $entrega, float $calificacion, ?string $retroalimentacion, int $usuarioId): void
    {
        $entrega->update([
            'calificacion' => $calificacion,
            'retroalimentacion' => $retroalimentacion,
            'estado' => Entrega::CALIFICADA,
            'calificada_por' => $usuarioId,
            'calificada_en' => now(),
        ]);

        // El componente al que pondera la actividad se recalcula con la nota nueva.
        $this->calculador->tras($entrega);
    }
}
