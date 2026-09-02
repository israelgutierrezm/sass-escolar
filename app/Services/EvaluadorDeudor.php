<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Admisiones\MatriculaOferta;
use App\Models\Finanzas\Adeudo;
use App\Models\Finanzas\BitacoraSituacionFinanciera;
use App\Models\Finanzas\SituacionPago;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Mueve al alumno a "moroso" (o lo regresa a "al corriente") según sus cargos
 * vencidos.
 *
 * Solo cuentan los cargos de planes marcados con `afecta_estatus_deudor`: hay
 * cobros —una credencial de reposición, un trámite suelto— que la escuela no
 * quiere que conviertan a nadie en deudor, y eso es una decisión del plan, no
 * del motor.
 *
 * El cambio se escribe en `bitacora_situacion_financiera`, que es append-only:
 * interesa saber qué situación tenía en marzo, no solo cuál tiene hoy.
 */
class EvaluadorDeudor
{
    public function __construct(private readonly ConvenioDePago $convenios) {}

    /**
     * Evalúa a un alumno. Devuelve la clave de la situación resultante, o null
     * si no hubo cambio.
     */
    public function evaluar(MatriculaOferta $matricula, ?CarbonImmutable $hoy = null): ?string
    {
        $hoy ??= CarbonImmutable::today();

        $tieneVencidos = Adeudo::query()
            ->where('matricula_oferta_id', $matricula->id)
            ->whereIn('estatus', [Adeudo::ESTATUS_PENDIENTE, Adeudo::ESTATUS_PARCIAL])
            ->whereDate('fecha_vencimiento', '<', $hoy->toDateString())
            ->whereHas('conceptoPlan.plan', fn (Builder $q) => $q->where('afecta_estatus_deudor', true))
            ->exists();

        /*
         * Un convenio VIGENTE gana, y por eso se pregunta antes: sus cargos
         * viejos ya no cuentan como vencidos —pasaron a `en_convenio`— pero la
         * situación tiene que decir que hay un acuerdo, no que está al
         * corriente. Son cosas distintas: al corriente no debe nada; con
         * convenio debe y se le está cobrando de otra forma.
         *
         * Y si una PARCIALIDAD se venció, manda «moroso»: el acuerdo se está
         * rompiendo, y esconderlo detrás de «con convenio» dejaría el atraso
         * invisible justo en el caso que hay que mirar. Declararlo incumplido
         * sigue siendo un acto de una persona; esto sólo lo reporta.
         */
        $convenio = $this->convenios->vigenteDe($matricula);

        $clave = match (true) {
            $convenio !== null && $convenio->tieneAtraso($hoy->toDateString()) => 'moroso',
            $convenio !== null => 'convenio',
            $tieneVencidos => 'moroso',
            default => 'corriente',
        };
        $situacion = SituacionPago::where('clave', $clave)->first();

        if ($situacion === null) {
            return null;
        }

        $ultima = BitacoraSituacionFinanciera::query()
            ->where('matricula_oferta_id', $matricula->id)
            ->latest('momento')
            ->first();

        // Sin cambio real no se escribe: la bitácora cuenta la historia, no el
        // paso de los días.
        if ($ultima !== null && $ultima->situacion_id === $situacion->id) {
            return null;
        }

        BitacoraSituacionFinanciera::create([
            'matricula_oferta_id' => $matricula->id,
            'situacion_id' => $situacion->id,
            'motivo' => match ($clave) {
                'convenio' => 'Convenio de pago vigente y al día.',
                'moroso' => $convenio !== null
                    ? 'Parcialidad del convenio vencida.'
                    : 'Cargos vencidos de un plan que afecta el estatus.',
                default => 'Sin cargos vencidos.',
            },
            'momento' => now(),
        ]);

        return $clave;
    }

    /**
     * Evalúa a todos los alumnos con cargos. Devuelve cuántos cambiaron.
     */
    public function evaluarTodos(?CarbonImmutable $hoy = null): int
    {
        $cambios = 0;

        MatriculaOferta::query()
            ->whereHas('adeudos')
            ->chunkById(200, function ($matriculas) use (&$cambios, $hoy) {
                foreach ($matriculas as $matricula) {
                    if ($this->evaluar($matricula, $hoy) !== null) {
                        $cambios++;
                    }
                }
            });

        return $cambios;
    }
}
