<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Admisiones\MatriculaOferta;
use App\Models\Finanzas\PlanCobro;
use App\Models\Finanzas\PlanCobroAlumno;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Qué plan de cobro le toca a quién.
 *
 * Con el modelo anterior el plan se "resolvía" por especificidad (oferta → plan
 * → carrera → global) y el alumno quedaba amarrado al que ganara. Ahora el plan
 * se le VINCULA explícitamente (`plan_cobro_alumno`), así que aquí quedan dos
 * preguntas distintas:
 *
 *  - `planesDe()`   — qué planes tiene vinculados este alumno (los que le cobran).
 *  - `candidatos()` — a qué alumnos ALCANZA un plan por su alcance
 *                     (campus + carreras), para la asignación masiva.
 *
 * Separarlas evita el error de cobrarle a alguien solo porque "cae en el
 * alcance": alcanzar es ser candidato, no estar cobrado.
 */
class ResolutorPlanCobro
{
    /** Planes actualmente vinculados a un alumno. */
    public function planesDe(MatriculaOferta $matricula): Collection
    {
        return PlanCobro::query()
            ->whereHas('asignaciones', fn (Builder $q) => $q
                ->where('matricula_oferta_id', $matricula->id)
                ->where('estatus', PlanCobroAlumno::ACTIVO))
            ->with(['ciclo', 'conceptos'])
            ->get();
    }

    /**
     * ¿El alcance del plan (campus + carreras) cubre a este alumno? El ciclo no
     * se exige aquí: un plan del ciclo entrante se le puede asignar a alguien
     * que todavía no está inscrito en él, que es justamente el caso de uso.
     */
    public function alcanzaA(PlanCobro $plan, MatriculaOferta $matricula): bool
    {
        $oferta = $matricula->oferta;

        if ($oferta === null) {
            return false;
        }

        $campus = $plan->campus->pluck('id')->all();
        $carreras = $plan->carreras->pluck('id')->all();

        // Sin restricción marcada, no se acota por esa dimensión.
        $enCampus = $campus === [] || in_array($oferta->campus_id, $campus, true);
        $enCarrera = $carreras === [] || in_array($oferta->carrera_id, $carreras, true);

        return $enCampus && $enCarrera;
    }

    /**
     * Alumnos ACTIVOS que caen en el alcance del plan y todavía no lo tienen
     * vinculado. Es la lista que ve el administrador para asignar en masa.
     *
     * @return Collection<int, MatriculaOferta>
     */
    public function candidatos(PlanCobro $plan): Collection
    {
        $campus = $plan->campus->pluck('id')->all();
        $carreras = $plan->carreras->pluck('id')->all();

        $yaAsignados = PlanCobroAlumno::query()
            ->where('plan_cobro_id', $plan->id)
            ->where('estatus', PlanCobroAlumno::ACTIVO)
            ->pluck('matricula_oferta_id');

        return MatriculaOferta::query()
            ->where('estatus', 'activo')
            ->whereNotIn('id', $yaAsignados)
            ->whereHas('oferta', function (Builder $q) use ($campus, $carreras) {
                if ($campus !== []) {
                    $q->whereIn('campus_id', $campus);
                }
                if ($carreras !== []) {
                    $q->whereIn('carrera_id', $carreras);
                }
            })
            ->with([
                'persona:id,nombre,primer_apellido,segundo_apellido',
                'oferta.carrera:id,nombre',
                'oferta.campus:id,nombre',
            ])
            ->orderBy('matricula')
            ->get();
    }
}
