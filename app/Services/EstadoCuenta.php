<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Admisiones\MatriculaOferta;
use App\Models\Finanzas\Adeudo;
use App\Models\Finanzas\AdeudoAjuste;
use App\Models\Finanzas\BitacoraSituacionFinanciera;
use App\Models\Finanzas\Pago;
use Carbon\CarbonImmutable;

/**
 * Arma el estado de cuenta de una matrícula: qué debe, qué pagó y si está
 * bloqueada.
 *
 * Vive como servicio y no dentro del controlador porque lo consultan tres
 * lugares distintos —la pantalla de finanzas, el expediente del alumno y (en
 * cuanto exista) el portal del alumno— y una sola de esas tres calculando el
 * saldo con su propio criterio sería una discrepancia que nadie sabría
 * explicar.
 */
class EstadoCuenta
{
    /**
     * @return array<string, mixed>
     */
    public function para(MatriculaOferta $matricula, ?CarbonImmutable $fecha = null): array
    {
        $fecha ??= CarbonImmutable::today();

        $adeudos = Adeudo::query()
            // `ajustes` explica por qué el total es el que es: sin él la pantalla
            // muestra un número que nadie sabe defender cuando lo reclaman.
            ->with(['concepto', 'ciclo:id,clave', 'ajustes'])
            ->deMatricula($matricula->id)
            ->orderBy('fecha_vencimiento')
            ->orderBy('id')
            ->get();

        $pagos = Pago::query()
            ->with(['metodoPago:id,clave,nombre', 'adeudos:id,periodo_etiqueta'])
            ->where('matricula_oferta_id', $matricula->id)
            ->orderByDesc('momento')
            ->orderByDesc('id')
            ->get();

        $porCobrar = $adeudos->filter(
            fn (Adeudo $a) => in_array($a->estatus, [Adeudo::ESTATUS_PENDIENTE, Adeudo::ESTATUS_PARCIAL], true)
        );

        $vencidos = $porCobrar->filter(fn (Adeudo $a) => $a->estaVencido($fecha->toDateString()));

        $situacion = BitacoraSituacionFinanciera::vigenteDe($matricula->id);

        return [
            'adeudos' => $adeudos->map(fn (Adeudo $a) => [
                'id' => $a->id,
                'concepto' => $a->concepto?->nombre,
                'periodo' => $a->periodo_etiqueta,
                'ciclo' => $a->ciclo?->clave,
                'monto' => (float) $a->monto,
                'recargos' => (float) $a->monto_recargos,
                'descuentos' => (float) $a->monto_descuentos,
                'total' => (float) $a->monto_total,
                'aplicado' => $a->montoAplicado(),
                'saldo' => $a->saldo(),
                'generacion' => $a->fecha_generacion?->toDateString(),
                'vencimiento' => $a->fecha_vencimiento?->toDateString(),
                'estatus' => $a->estatus,
                /*
                 * De qué convenio es parcialidad este cargo, si lo es. Sin
                 * decirlo, en el estado de cuenta aparece «Colegiatura» con una
                 * fecha que no cuadra con ninguna del plan y nadie sabe de
                 * dónde salió. El cargo CUBIERTO por un convenio se reconoce
                 * por su estatus `en_convenio`.
                 */
                'convenio_id' => $a->convenio_id,
                'vencido' => $a->estaVencido($fecha->toDateString()),
                'dias_vencido' => $a->estaVencido($fecha->toDateString())
                    ? (int) $a->fecha_vencimiento->diffInDays($fecha)
                    : 0,
                // Renglón por renglón: qué beca, qué descuento y qué recargo
                // movieron el monto. El signo ya viene de la base.
                'ajustes' => $a->ajustes->map(fn (AdeudoAjuste $j) => [
                    'tipo' => $j->tipo,
                    'etiqueta' => $j->etiqueta,
                    'monto' => (float) $j->monto,
                    'periodo' => $j->periodo_aplicado,
                ])->values(),
            ])->values(),

            'pagos' => $pagos->map(fn (Pago $p) => [
                'id' => $p->id,
                'monto' => (float) $p->monto,
                'metodo' => $p->metodoPago?->nombre,
                'referencia' => $p->referencia,
                'estatus' => $p->estatus,
                'cobrado' => $p->estaCobrado(),
                'momento' => $p->momento?->toDateTimeString(),
                'sin_aplicar' => $p->montoSinAplicar(),
                'cubre' => $p->adeudos->pluck('periodo_etiqueta')->filter()->values(),
            ])->values(),

            'resumen' => [
                // El saldo cuenta solo lo que sigue por cobrar: un adeudo
                // condonado o cancelado no es dinero que la escuela espere.
                'saldo' => round($porCobrar->sum(fn (Adeudo $a) => $a->saldo()), 2),
                'vencido' => round($vencidos->sum(fn (Adeudo $a) => $a->saldo()), 2),
                'adeudos_por_cobrar' => $porCobrar->count(),
                'adeudos_vencidos' => $vencidos->count(),
                // Solo pagos que de verdad entraron: lo pendiente de confirmar
                // se reporta aparte para que nadie lo sume como cobrado.
                'pagado' => round($pagos->where('estatus', Pago::ESTATUS_COMPLETADO)->sum('monto'), 2),
                'por_confirmar' => round($pagos->where('estatus', Pago::ESTATUS_PENDIENTE)->sum('monto'), 2),
                'a_favor' => round(
                    $pagos->where('estatus', Pago::ESTATUS_COMPLETADO)
                        ->sum(fn (Pago $p) => max(0, $p->montoSinAplicar())),
                    2
                ),
            ],

            'situacion' => $situacion === null ? null : [
                'clave' => $situacion->situacion?->clave,
                'nombre' => $situacion->situacion?->nombre,
                'bloquea' => (bool) $situacion->situacion?->bloquea,
                'motivo' => $situacion->motivo,
                'momento' => $situacion->momento?->toDateTimeString(),
            ],

            'bitacora' => BitacoraSituacionFinanciera::query()
                ->with('situacion:id,clave,nombre,bloquea')
                ->where('matricula_oferta_id', $matricula->id)
                ->orderByDesc('momento')
                ->orderByDesc('id')
                ->get()
                ->map(fn (BitacoraSituacionFinanciera $b) => [
                    'id' => $b->id,
                    'situacion' => $b->situacion?->nombre,
                    'bloquea' => (bool) $b->situacion?->bloquea,
                    'motivo' => $b->motivo,
                    'momento' => $b->momento?->toDateTimeString(),
                ])->values(),
        ];
    }

    /**
     * Si la matrícula está bloqueada para trámites (reinscribirse, ver
     * calificaciones). Lo dice la situación vigente, NO el saldo: hay escuelas
     * que no bloquean nunca y otras que bloquean al primer adeudo, y esa
     * decisión vive en el catálogo, no en el código.
     */
    public function estaBloqueada(MatriculaOferta $matricula): bool
    {
        return (bool) BitacoraSituacionFinanciera::vigenteDe($matricula->id)?->situacion?->bloquea;
    }
}
