<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Admisiones\MatriculaOferta;
use App\Models\Finanzas\PlanCobro;
use Carbon\CarbonImmutable;

/**
 * Qué plan de cobro le toca a una matrícula.
 *
 * Gana el MÁS ESPECÍFICO de los vigentes: oferta → plan de estudios → carrera →
 * global. Es el mismo criterio de `reglas_matricula`, y por la misma razón: la
 * escuela define un esquema general y lo excepciona donde hace falta ("todos
 * pagan así, salvo la maestría en línea"). Buscar el más específico evita
 * tener que dar de alta un plan de cobro por cada oferta solo para repetir el
 * mismo monto.
 *
 * Si empatan dos del mismo nivel —dos planes globales vigentes a la vez, que es
 * una configuración mal hecha— gana el de `vigente_desde` más reciente: es el
 * último que alguien quiso poner en marcha.
 */
class ResolutorPlanCobro
{
    /** De más específico a más general. */
    private const PRECEDENCIA = [
        PlanCobro::APLICA_OFERTA,
        PlanCobro::APLICA_PLAN,
        PlanCobro::APLICA_CARRERA,
        PlanCobro::APLICA_GLOBAL,
    ];

    public function para(MatriculaOferta $matricula, ?CarbonImmutable $fecha = null): ?PlanCobro
    {
        $matricula->loadMissing('oferta');
        $oferta = $matricula->oferta;

        if ($oferta === null) {
            return null;
        }

        $fecha ??= CarbonImmutable::today();

        $identificador = [
            PlanCobro::APLICA_OFERTA => $oferta->id,
            PlanCobro::APLICA_PLAN => $oferta->plan_id,
            PlanCobro::APLICA_CARRERA => $oferta->carrera_id,
            PlanCobro::APLICA_GLOBAL => null,
        ];

        foreach (self::PRECEDENCIA as $tipo) {
            $consulta = PlanCobro::query()
                ->vigentes($fecha->toDateString())
                ->where('aplica_a_tipo', $tipo);

            $id = $identificador[$tipo];

            $consulta = $id === null
                ? $consulta->whereNull('aplica_a_id')
                : $consulta->where('aplica_a_id', $id);

            $plan = $consulta->orderByDesc('vigente_desde')->orderByDesc('id')->first();

            if ($plan !== null) {
                return $plan;
            }
        }

        return null;
    }
}
