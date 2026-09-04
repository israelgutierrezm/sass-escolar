<?php

declare(strict_types=1);

namespace App\Reportes\Definiciones;

use App\Reportes\DefinicionReporte;

/**
 * La cola: lo que el motor levantó y nadie ha mirado.
 *
 * Es una COLA DE TRABAJO, no un padrón. Por eso se ordena por la fecha en que
 * apareció —lo más viejo primero, que es lo que lleva más tiempo esperando— y
 * no por severidad: ordenada por gravedad, lo de hace tres semanas no se toca
 * nunca.
 */
class SenalesPorRevisar extends DefinicionReporte
{
    public function clave(): string
    {
        return 'senales-por-revisar';
    }

    public function titulo(): string
    {
        return 'Señales por revisar';
    }

    public function descripcion(): string
    {
        return 'Lo que el motor levantó y todavía nadie ha validado ni descartado. Una señal no es '
            .'una sanción: es una revisión pendiente.';
    }

    public function fuente(): string
    {
        return 'senales_permanencia';
    }

    public function areaSugerida(): string
    {
        return 'permanencia';
    }

    public function filtrosFijos(): array
    {
        return ['estado_triage' => ['nueva'], 'estado_senal' => ['activa']];
    }

    public function columnasPorOmision(): ?array
    {
        return ['matricula', 'alumno', 'programa_academico', 'campus', 'categoria', 'regla',
            'severidad', 'primera_vez_en'];
    }

    public function ordenPorOmision(): ?array
    {
        return ['primera_vez_en', 'asc'];
    }
}
