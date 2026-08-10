<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Admisiones\MatriculaOferta;
use App\Models\Finanzas\Adeudo;
use App\Models\Finanzas\AdeudoAjuste;
use App\Models\Finanzas\ConceptoPlan;
use App\Models\Finanzas\PlanCobro;
use App\Models\Finanzas\PlanCobroAlumno;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * El motor de cobro: convierte las líneas del plan en los cargos del alumno.
 *
 * Vincular un plan a un alumno es lo que dispara la generación: se crea un
 * adeudo por cada línea del plan, ya con becas y descuentos aplicados y con su
 * desglose en `adeudo_ajustes`. No se espera a que el alumno se inscriba al
 * ciclo, porque en muchas escuelas pagar es el requisito PARA inscribirse.
 *
 * **Idempotente.** Correrlo dos veces no duplica: la pareja (matrícula, línea)
 * se comprueba antes de crear. Si más adelante se agregan líneas al plan, volver
 * a generar crea solo las que faltan.
 */
class GeneradorAdeudos
{
    public function __construct(
        private readonly CalculadorCargo $calculador,
    ) {}

    /**
     * Vincula el plan a los alumnos y les genera sus cargos.
     *
     * @param  array<int, int>  $matriculaIds
     * @return array{asignados: int, cargos: int, omitidos: int}
     */
    public function asignarPlan(PlanCobro $plan, array $matriculaIds): array
    {
        $plan->loadMissing('conceptos');

        $asignados = 0;
        $cargos = 0;
        $omitidos = 0;

        foreach (array_unique($matriculaIds) as $id) {
            $matricula = MatriculaOferta::find($id);

            if ($matricula === null) {
                $omitidos++;

                continue;
            }

            DB::transaction(function () use ($plan, $matricula, &$asignados, &$cargos) {
                $asignacion = PlanCobroAlumno::firstOrNew([
                    'plan_cobro_id' => $plan->id,
                    'matricula_oferta_id' => $matricula->id,
                ]);

                $eraNueva = ! $asignacion->exists;

                $asignacion->fill([
                    'estatus' => PlanCobroAlumno::ACTIVO,
                    'asignado_en' => now(),
                    'asignado_por' => Auth::id(),
                ])->save();

                if ($eraNueva) {
                    $asignados++;
                }

                $cargos += $this->generarCargos($plan, $matricula);
            });
        }

        return ['asignados' => $asignados, 'cargos' => $cargos, 'omitidos' => $omitidos];
    }

    /**
     * Crea los adeudos que le faltan a este alumno para este plan.
     * Devuelve cuántos creó.
     */
    public function generarCargos(PlanCobro $plan, MatriculaOferta $matricula): int
    {
        $creados = 0;

        foreach ($plan->conceptos as $linea) {
            $yaExiste = Adeudo::withTrashed()
                ->where('matricula_oferta_id', $matricula->id)
                ->where('concepto_plan_id', $linea->id)
                ->exists();

            if ($yaExiste) {
                continue;
            }

            $this->crearAdeudo($plan, $linea, $matricula);
            $creados++;
        }

        return $creados;
    }

    /** Crea el adeudo de una línea, con su desglose de becas y descuentos. */
    private function crearAdeudo(PlanCobro $plan, ConceptoPlan $linea, MatriculaOferta $matricula): Adeudo
    {
        $calculo = $this->calculador->para($linea, $matricula);
        $descuentos = abs(array_sum(array_column($calculo['ajustes'], 'monto')));

        $adeudo = Adeudo::create([
            'matricula_oferta_id' => $matricula->id,
            'concepto_id' => $linea->concepto_id,
            'concepto_plan_id' => $linea->id,
            'ciclo_id' => $plan->ciclo_id,
            'periodo_etiqueta' => $linea->periodoEtiqueta(),
            'monto' => $calculo['monto'],
            'monto_recargos' => 0,
            'monto_descuentos' => $descuentos,
            'monto_total' => $calculo['total'],
            'fecha_generacion' => now()->toDateString(),
            // Sin fecha límite configurada, el cargo vence al cierre del ciclo o,
            // en su defecto, el mismo día: nunca queda sin vencimiento porque la
            // cartera se ordena por esa fecha.
            'fecha_vencimiento' => $linea->fecha_limite?->toDateString()
                ?? $plan->ciclo?->fecha_fin?->toDateString()
                ?? now()->toDateString(),
            'estatus' => Adeudo::ESTATUS_PENDIENTE,
        ]);

        foreach ($calculo['ajustes'] as $ajuste) {
            AdeudoAjuste::create($ajuste + ['adeudo_id' => $adeudo->id]);
        }

        return $adeudo;
    }

    /**
     * Recalcula los cargos PENDIENTES de un alumno tras un cambio de beca.
     *
     * Los ya pagados no se tocan: el dinero que entró no se reescribe. Solo se
     * recomponen los que aún se le pueden cobrar distinto.
     */
    public function recalcularPendientes(MatriculaOferta $matricula): int
    {
        $adeudos = Adeudo::query()
            ->where('matricula_oferta_id', $matricula->id)
            ->where('estatus', Adeudo::ESTATUS_PENDIENTE)
            ->whereNotNull('concepto_plan_id')
            ->with('conceptoPlan')
            ->get();

        $tocados = 0;

        foreach ($adeudos as $adeudo) {
            $linea = $adeudo->conceptoPlan;

            if ($linea === null) {
                continue;
            }

            DB::transaction(function () use ($adeudo, $linea, $matricula, &$tocados) {
                // Se rehacen solo los beneficios; el recargo por mora lo mantiene
                // su propio servicio y no debe perderse en el recálculo.
                $adeudo->ajustes()
                    ->whereIn('tipo', [AdeudoAjuste::TIPO_BECA, AdeudoAjuste::TIPO_DESCUENTO])
                    ->delete();

                $calculo = $this->calculador->para($linea, $matricula);

                foreach ($calculo['ajustes'] as $ajuste) {
                    AdeudoAjuste::create($ajuste + ['adeudo_id' => $adeudo->id]);
                }

                $descuentos = abs(array_sum(array_column($calculo['ajustes'], 'monto')));
                $recargos = (float) $adeudo->monto_recargos;

                $adeudo->update([
                    'monto' => $calculo['monto'],
                    'monto_descuentos' => $descuentos,
                    'monto_total' => max(0, round($calculo['total'] + $recargos, 2)),
                ]);

                $tocados++;
            });
        }

        return $tocados;
    }

    /**
     * Genera lo que falte para TODA la escuela, plan por plan.
     *
     * ── Por qué se recorre por plan y no por alumno ────────────────────────
     * Un plan trae sus líneas una vez y sirve para sus cientos de asignados. Al
     * revés —recorriendo matrículas y preguntándole a cada una por sus planes—
     * se vuelve a leer el mismo plan y sus mismas líneas una vez por alumno, que
     * es lo que hace `generarPara` porque para una sola matrícula da igual.
     *
     * ── Por qué no carga las matrículas de golpe ───────────────────────────
     * Se recorre en bloques con `chunkById`. Una escuela con miles de alumnos no
     * cabe en memoria de una sentada, y esto corre de madrugada sin nadie
     * mirando: quedarse sin memoria a la mitad dejaría media cartera generada y
     * media no, sin que nadie se entere hasta el corte.
     *
     * Es idempotente como el resto del motor —`generarCargos` comprueba la
     * pareja (matrícula, línea) antes de crear— y desde la migración del único
     * de generación, la base lo sostiene además del `SELECT`.
     *
     * ── Un plan roto no cancela a los demás ────────────────────────────────
     * Cada plan se aísla, igual que el barrido aísla cada tenant. No es una
     * precaución teórica: la escuela de ejemplo tiene sus dos planes apuntando a
     * un `ciclo_id` que ya no está en `ciclos` —restos de una resiembra con las
     * comprobaciones de foránea apagadas, porque la foránea sí existe—, y el
     * primer cargo revienta. Sin aislar, esa sola fila dejaría a la escuela
     * ENTERA sin emitir, de madrugada y sin nadie mirando. Lo que falla se
     * devuelve para que el comando lo enseñe en vez de reportar un «ok» con los
     * cargos de menos.
     *
     * Un plan que falla a la mitad deja emitido lo que alcanzó. No es un
     * problema: la siguiente corrida completa el resto, porque generar es
     * idempotente.
     *
     * @return array{planes: int, matriculas: int, cargos: int, fallidos: array<int, array{plan: int, motivo: string}>}
     */
    public function generarParaTodas(): array
    {
        $planes = PlanCobro::query()->with(['conceptos', 'ciclo'])->get();

        $matriculas = 0;
        $cargos = 0;
        $conAsignados = 0;
        $fallidos = [];

        foreach ($planes as $plan) {
            $asignados = MatriculaOferta::query()
                ->whereIn('id', PlanCobroAlumno::query()
                    ->where('plan_cobro_id', $plan->id)
                    ->where('estatus', PlanCobroAlumno::ACTIVO)
                    ->select('matricula_oferta_id'));

            if (! $asignados->exists()) {
                continue;
            }

            $conAsignados++;

            try {
                $asignados->chunkById(200, function ($lote) use ($plan, &$matriculas, &$cargos) {
                    foreach ($lote as $matricula) {
                        $matriculas++;
                        $cargos += $this->generarCargos($plan, $matricula);
                    }
                });
            } catch (Throwable $e) {
                $fallidos[] = ['plan' => $plan->id, 'motivo' => $e->getMessage()];
            }
        }

        return [
            'planes' => $conAsignados,
            'matriculas' => $matriculas,
            'cargos' => $cargos,
            'fallidos' => $fallidos,
        ];
    }

    /** Vuelve a generar lo que falte para todos los planes activos del alumno. */
    public function generarPara(MatriculaOferta $matricula): array
    {
        $planes = PlanCobro::query()
            ->whereHas('asignaciones', fn ($q) => $q
                ->where('matricula_oferta_id', $matricula->id)
                ->where('estatus', PlanCobroAlumno::ACTIVO))
            ->with(['conceptos', 'ciclo'])
            ->get();

        $generados = 0;

        foreach ($planes as $plan) {
            $generados += $this->generarCargos($plan, $matricula);
        }

        return ['generados' => $generados, 'planes' => $planes->count()];
    }
}
