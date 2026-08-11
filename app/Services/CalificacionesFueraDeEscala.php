<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Academico\PlanEstudio;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Qué calificaciones ya capturadas no cumplen la escala que hoy tiene su plan.
 *
 * ── Por qué existe ─────────────────────────────────────────────────────────
 * Cambiar la escala de un plan sólo afecta a lo que se capture DESPUÉS: el
 * historial no se toca. Es lo correcto —son actas ya emitidas, y reescribir una
 * calificación pasada sin que nadie lo pida es exactamente lo que un sistema
 * escolar no debe hacer—, pero deja una incoherencia callada: la escuela
 * configura «aquí calificamos con enteros» y sigue viendo 8.5 en los historial académico,
 * sin entender por qué.
 *
 * Esto no arregla nada. Lo cuenta, que es lo que permite decidir con datos en
 * vez de con una sospecha.
 *
 * ── Qué se considera fuera de escala ───────────────────────────────────────
 * Dos cosas distintas, y conviene no mezclarlas al leer el resultado:
 * - **precisión**: tiene más decimales de los que el plan permite hoy.
 * - **rango**: se sale de la mínima o la máxima configuradas.
 *
 * Lo primero suele venir de un cambio de configuración reciente; lo segundo
 * casi siempre significa que el plan cambió de escala entera (de 0-100 a 0-10,
 * por ejemplo) y el historial se quedó en la anterior. El segundo caso es más
 * grave: un 85 en una escala de 0 a 10 no es un decimal de más, es otra unidad.
 */
class CalificacionesFueraDeEscala
{
    /**
     * Cuántas hay por plan. Sólo los planes que tengan alguna.
     *
     * ── Se cuenta en SQL, no en PHP ────────────────────────────────────────
     * El historial de una escuela son decenas de miles de renglones y esto se
     * consulta al abrir la pantalla de configuración. Traerlos para contarlos
     * en memoria haría lenta justo la pantalla desde la que se decide.
     *
     * @return array<int, array{precision: int, rango: int}> plan_id => conteos
     */
    public function porPlan(): array
    {
        $resultado = [];

        foreach ($this->planesConEscala() as $plan) {
            $conteos = $this->contarDe($plan);

            if ($conteos['precision'] > 0 || $conteos['rango'] > 0) {
                $resultado[$plan->id] = $conteos;
            }
        }

        return $resultado;
    }

    /**
     * El detalle de un plan, para poder mirarlas.
     *
     * @return Collection<int, object>
     */
    public function deUnPlan(PlanEstudio $plan, int $limite = 200): Collection
    {
        $decimales = (int) ($plan->decimales_calificacion ?? 2);

        return $this->consultaDe($plan)
            /*
             * SÓLO las que no cuadran, con el mismo criterio que las cuenta.
             *
             * Sin este filtro la pantalla listaba TODAS las calificaciones del
             * plan —la que sobra y la que está perfecta—, así que la lista no
             * decía nada y obligaba a buscar a ojo lo que había que corregir.
             * El conteo sí filtraba: eran dos criterios donde debía haber uno.
             */
            ->where(function ($q) use ($plan, $decimales) {
                $q->whereRaw("ROUND(h.calificacion, {$decimales}) <> h.calificacion")
                    ->orWhere('h.calificacion', '<', (float) $plan->calificacion_minima)
                    ->orWhere('h.calificacion', '>', (float) $plan->calificacion_maxima);
            })
            ->join('matricula_oferta as m', 'm.id', '=', 'h.matricula_oferta_id')
            ->leftJoin('personas as p', 'p.id', '=', 'm.persona_id')
            ->leftJoin('plan_materias as pm', 'pm.id', '=', 'h.plan_materia_id')
            ->leftJoin('asignaturas as a', 'a.id', '=', 'pm.asignatura_id')
            ->leftJoin('ciclos as c', 'c.id', '=', 'h.ciclo_id')
            ->selectRaw("
                h.id,
                m.matricula,
                TRIM(CONCAT(COALESCE(p.nombre,''), ' ', COALESCE(p.primer_apellido,''), ' ', COALESCE(p.segundo_apellido,''))) as alumno,
                a.nombre as materia,
                c.clave as ciclo,
                h.calificacion,
                ROUND(h.calificacion, {$decimales}) as sugerida,
                h.acta_folio
            ")
            ->orderBy('m.matricula')
            ->limit($limite)
            ->get();
    }

    // ── Interno ────────────────────────────────────────────────────────────

    /** @return array{precision: int, rango: int} */
    private function contarDe(PlanEstudio $plan): array
    {
        $decimales = (int) ($plan->decimales_calificacion ?? 2);

        $fila = $this->consultaDe($plan)
            ->selectRaw("
                SUM(CASE WHEN ROUND(h.calificacion, {$decimales}) <> h.calificacion THEN 1 ELSE 0 END) as precision_mal,
                SUM(CASE WHEN h.calificacion < ? OR h.calificacion > ? THEN 1 ELSE 0 END) as rango_mal
            ", [(float) $plan->calificacion_minima, (float) $plan->calificacion_maxima])
            ->first();

        return [
            'precision' => (int) ($fila->precision_mal ?? 0),
            'rango' => (int) ($fila->rango_mal ?? 0),
        ];
    }

    /**
     * El historial de este plan, con calificación puesta.
     *
     * Se llega al plan por la OFERTA, que es de donde cuelga la matrícula: el
     * renglón de historial no lo guarda.
     */
    private function consultaDe(PlanEstudio $plan)
    {
        return DB::table('historial as h')
            ->join('matricula_oferta as mo', 'mo.id', '=', 'h.matricula_oferta_id')
            ->join('oferta as o', 'o.id', '=', 'mo.oferta_id')
            ->where('o.plan_id', $plan->id)
            ->whereNull('h.deleted_at')
            ->whereNotNull('h.calificacion');
    }

    /**
     * Los planes que tienen escala declarada.
     *
     * Sin mínima y máxima no hay contra qué comparar, y un plan a medio
     * configurar no debe salir en la lista como si tuviera problemas.
     *
     * @return Collection<int, PlanEstudio>
     */
    private function planesConEscala(): Collection
    {
        return PlanEstudio::query()
            ->whereNotNull('calificacion_minima')
            ->whereNotNull('calificacion_maxima')
            ->get();
    }
}
