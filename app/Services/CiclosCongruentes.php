<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Admisiones\MatriculaOferta;
use App\Models\ControlEscolar\Ciclo;
use Illuminate\Support\Collection;

/**
 * Los ciclos que corresponden a DONDE cursa un alumno.
 *
 * Un ciclo puede acotarse a ciertos campus y/o niveles de estudio (igual que en
 * los grupos). Para un alumno concreto solo aplican los que incluyen su campus
 * (o no acotan campus) y su nivel (o no acotan nivel). Vive en un solo lugar
 * para que cualquier desplegable de ciclo por alumno —historial académico, inscripción,
 * lo que venga— sea congruente con la misma regla.
 */
class CiclosCongruentes
{
    /**
     * @return Collection<int, Ciclo>
     */
    public function paraAlumno(MatriculaOferta $alumno): Collection
    {
        $alumno->loadMissing('oferta.programaAcademico');
        $campusId = $alumno->oferta?->campus_id;
        $nivelId = $alumno->oferta?->programaAcademico?->nivel_estudios_id;

        return Ciclo::query()
            ->with(['campus:id', 'niveles:id'])
            ->orderByDesc('fecha_inicio')
            ->get(['id', 'clave', 'nombre'])
            ->filter(function (Ciclo $ciclo) use ($campusId, $nivelId) {
                $campusOk = $ciclo->campus->isEmpty() || ($campusId !== null && $ciclo->campus->contains('id', $campusId));
                $nivelOk = $ciclo->niveles->isEmpty() || ($nivelId !== null && $ciclo->niveles->contains('id', $nivelId));

                return $campusOk && $nivelOk;
            })
            ->values();
    }
}
