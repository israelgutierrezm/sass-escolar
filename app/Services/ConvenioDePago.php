<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\AvisoParaElUsuario;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\Finanzas\Adeudo;
use App\Models\Finanzas\BitacoraSituacionFinanciera;
use App\Models\Finanzas\ConvenioPago;
use App\Models\Finanzas\SituacionPago;
use App\Models\Identidad\Usuario;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Reprogramar una deuda sin perdonarla.
 *
 * ── Qué cambia de verdad al firmar ─────────────────────────────────────────
 * Tres cosas, y las tres importan: los cargos viejos dejan de pesar en el
 * estado de cuenta y de contar como vencidos; **la mora deja de correr** —una
 * parcialidad no tiene línea de plan, y `CalculadorRecargos` devuelve cero sin
 * ella, que es justo lo que hace que valga la pena firmar—; y el alumno pasa a
 * la situación «con convenio», que es la fila del catálogo que llevaba desde
 * 7.1 sin que nadie la escribiera.
 *
 * ── La suma tiene que CUADRAR al centavo ───────────────────────────────────
 * Si las parcialidades pudieran sumar menos que el saldo cubierto, esta
 * pantalla sería una segunda puerta para perdonar deuda —sin el permiso de
 * condonar y sin su bitácora—. Para perdonar se condona primero y se acuerda
 * después lo que quede.
 *
 * ── Y ni un peso de más ────────────────────────────────────────────────────
 * Tampoco puede sumar de MÁS. Cobrar intereses por diferir es una decisión que
 * la escuela puede tomar, pero tiene que ser un cargo con su concepto —para que
 * el CFDI lo diga y el reporte lo separe—, no un número escondido dentro de
 * unas parcialidades que dicen «colegiatura».
 */
class ConvenioDePago
{
    /**
     * Los cargos que se pueden acordar: los que todavía pesan y no están ya en
     * otro convenio.
     *
     * @return Collection<int, Adeudo>
     */
    public function elegibles(MatriculaOferta $matricula, ?int $conceptoId = null): Collection
    {
        return Adeudo::query()
            ->deMatricula($matricula->id)
            ->porCobrar()
            ->when($conceptoId !== null, fn ($q) => $q->where('concepto_id', $conceptoId))
            ->whereNotIn('id', DB::table('convenio_adeudo')->whereNull('deleted_at')->select('adeudo_id'))
            ->with('concepto:id,nombre')
            ->orderBy('fecha_vencimiento')
            ->get();
    }

    /**
     * @param  array<int, int>  $adeudoIds
     * @param  array<int, array{fecha: string, monto: float}>  $parcialidades
     */
    public function crear(
        MatriculaOferta $matricula,
        array $adeudoIds,
        array $parcialidades,
        string $motivo,
        string $firmadoEn,
        ?Usuario $autoriza,
    ): ConvenioPago {
        AvisoParaElUsuario::aMenosQue($adeudoIds !== [], 422, 'No elegiste ningún cargo que acordar.');
        AvisoParaElUsuario::aMenosQue($parcialidades !== [], 422, 'Un convenio necesita al menos una parcialidad.');

        $cargos = Adeudo::query()
            ->whereIn('id', $adeudoIds)
            ->deMatricula($matricula->id)
            ->porCobrar()
            ->get();

        AvisoParaElUsuario::aMenosQue(
            $cargos->count() === count(array_unique($adeudoIds)),
            422,
            'Alguno de los cargos que elegiste ya no está por cobrar, o no es de este alumno.',
        );

        $yaEnOtro = DB::table('convenio_adeudo')
            ->whereIn('adeudo_id', $cargos->pluck('id'))
            ->whereNull('deleted_at')
            ->count();

        AvisoParaElUsuario::si(
            $yaEnOtro > 0,
            422,
            'Alguno de esos cargos ya está en otro convenio. El mismo dinero no se acuerda dos veces.',
        );

        $conceptos = $cargos->pluck('concepto_id')->unique();

        AvisoParaElUsuario::si(
            $conceptos->count() !== 1,
            422,
            'Un convenio cubre cargos de un solo concepto: el CFDI se emite contra el concepto, y mezclarlos '
            .'haría que un comprobante dijera «enseñanza» sobre dinero que no lo es. Si hay que acordar dos, son dos convenios.',
        );

        $cubierto = round($cargos->sum(fn (Adeudo $a) => $a->saldo()), 2);

        AvisoParaElUsuario::si($cubierto <= 0, 422, 'Esos cargos no tienen saldo que acordar.');

        $suma = round(array_sum(array_map(fn (array $p) => (float) $p['monto'], $parcialidades)), 2);

        AvisoParaElUsuario::si(
            abs($suma - $cubierto) >= 0.005,
            422,
            'Las parcialidades suman '.number_format($suma, 2).' y el saldo acordado es '
            .number_format($cubierto, 2).'. Un convenio reprograma, no perdona: si hay que perdonar, condona primero.',
        );

        foreach ($parcialidades as $i => $p) {
            AvisoParaElUsuario::si(
                (float) $p['monto'] <= 0,
                422,
                'La parcialidad '.($i + 1).' no tiene importe.',
            );

            AvisoParaElUsuario::si(
                $p['fecha'] < $firmadoEn,
                422,
                'La parcialidad '.($i + 1).' vence antes de la firma del convenio.',
            );
        }

        return DB::transaction(function () use ($matricula, $cargos, $parcialidades, $motivo, $firmadoEn, $autoriza, $cubierto, $conceptos) {
            $convenio = ConvenioPago::create([
                'matricula_oferta_id' => $matricula->id,
                'concepto_id' => $conceptos->first(),
                'motivo' => $motivo,
                'firmado_en' => $firmadoEn,
                'monto_cubierto' => $cubierto,
                'estatus' => ConvenioPago::VIGENTE,
                'autorizado_por' => $autoriza?->getKey(),
            ]);

            foreach ($cargos as $cargo) {
                $convenio->cubiertos()->attach($cargo->id, ['saldo_cubierto' => round($cargo->saldo(), 2)]);
                // No se cancelan: siguen explicando qué se debía y desde cuándo.
                $cargo->update(['estatus' => Adeudo::ESTATUS_EN_CONVENIO]);
            }

            foreach (array_values($parcialidades) as $i => $p) {
                Adeudo::create([
                    'matricula_oferta_id' => $matricula->id,
                    'concepto_id' => $conceptos->first(),
                    // SIN línea de plan a propósito: es lo que deja la mora
                    // parada y lo que impide que el generador de cargos la
                    // recalcule como si fuera una colegiatura del ciclo.
                    'concepto_plan_id' => null,
                    'convenio_id' => $convenio->id,
                    'periodo_etiqueta' => 'CONV-'.$convenio->id.'-'.($i + 1),
                    'monto' => round((float) $p['monto'], 2),
                    'monto_total' => round((float) $p['monto'], 2),
                    'fecha_generacion' => $firmadoEn,
                    'fecha_vencimiento' => $p['fecha'],
                    'estatus' => Adeudo::ESTATUS_PENDIENTE,
                ]);
            }

            $this->anotarSituacion($matricula, 'convenio', "Convenio #{$convenio->id}: {$motivo}");

            return $convenio->fresh();
        });
    }

    /**
     * Deshace un convenio capturado por error.
     *
     * Sólo mientras no haya entrado un peso: con abonos de por medio, devolver
     * los cargos originales completos cobraría dos veces el mismo dinero, y
     * repartir lo abonado entre ellos sería inventar un reparto que nadie
     * acordó. Para eso está incumplir, que acelera en vez de deshacer.
     */
    public function cancelar(ConvenioPago $convenio, string $motivo): void
    {
        AvisoParaElUsuario::aMenosQue(
            $convenio->estaVigente(),
            422,
            'Ese convenio ya está cerrado.',
        );

        AvisoParaElUsuario::si(
            $convenio->tieneAbonos(),
            422,
            'Ese convenio ya recibió pagos: no se puede cancelar. Si se rompió, decláralo incumplido.',
        );

        DB::transaction(function () use ($convenio, $motivo) {
            $convenio->parcialidades()->update(['estatus' => Adeudo::ESTATUS_CANCELADO]);

            foreach ($convenio->cubiertos as $cargo) {
                $cargo->update(['estatus' => Adeudo::ESTATUS_PENDIENTE]);
            }

            /*
             * El pivote se BORRA de verdad. Su único es sobre `adeudo_id` a
             * secas y MySQL no distingue una fila dada de baja, así que con
             * borrado lógico ese cargo no podría entrar nunca a otro convenio
             * — y capturar mal uno y rehacerlo es exactamente lo que va a pasar.
             */
            DB::table('convenio_adeudo')->where('convenio_id', $convenio->id)->delete();

            $convenio->update([
                'estatus' => ConvenioPago::CANCELADO,
                'cerrado_en' => now(),
                'motivo_cierre' => $motivo,
            ]);

            $this->anotarSituacion($convenio->matricula, 'moroso', "Convenio #{$convenio->id} cancelado: {$motivo}");
        });
    }

    /**
     * El convenio se rompió: lo que falta se vence hoy.
     *
     * NO devuelve los cargos originales. Ver la nota de `cancelar`: es la
     * cláusula de vencimiento anticipado, y es la única forma de romperlo sin
     * cobrar dos veces ni inventar repartos.
     */
    public function incumplir(ConvenioPago $convenio, string $motivo): int
    {
        AvisoParaElUsuario::aMenosQue(
            $convenio->estaVigente(),
            422,
            'Ese convenio ya está cerrado.',
        );

        return DB::transaction(function () use ($convenio, $motivo) {
            $vencidas = 0;
            $hoy = now()->toDateString();

            foreach ($convenio->parcialidades()->porCobrar()->get() as $parcialidad) {
                if ($parcialidad->fecha_vencimiento->toDateString() > $hoy) {
                    $parcialidad->update(['fecha_vencimiento' => $hoy]);
                }

                $vencidas++;
            }

            $convenio->update([
                'estatus' => ConvenioPago::INCUMPLIDO,
                'cerrado_en' => now(),
                'motivo_cierre' => $motivo,
            ]);

            $this->anotarSituacion($convenio->matricula, 'moroso', "Convenio #{$convenio->id} incumplido: {$motivo}");

            return $vencidas;
        });
    }

    /**
     * Da por cumplido el convenio cuando ya no le queda saldo.
     *
     * Se reconoce solo y no lo declara nadie: que esté pagado es un hecho
     * aritmético, no una decisión. Al revés que incumplir, que sí lo es.
     */
    public function revisarCumplimiento(ConvenioPago $convenio): bool
    {
        if (! $convenio->estaVigente() || $convenio->saldo() > 0.004) {
            return false;
        }

        $convenio->update([
            'estatus' => ConvenioPago::CUMPLIDO,
            'cerrado_en' => now(),
            'motivo_cierre' => 'Pagado por completo.',
        ]);

        $this->anotarSituacion($convenio->matricula, 'corriente', "Convenio #{$convenio->id} cumplido.");

        return true;
    }

    /** El convenio vigente de una matrícula, si tiene. */
    public function vigenteDe(MatriculaOferta $matricula): ?ConvenioPago
    {
        return ConvenioPago::query()
            ->where('matricula_oferta_id', $matricula->id)
            ->vigentes()
            ->latest('id')
            ->first();
    }

    private function anotarSituacion(?MatriculaOferta $matricula, string $clave, string $motivo): void
    {
        if ($matricula === null) {
            return;
        }

        $situacion = SituacionPago::where('clave', $clave)->first();

        if ($situacion === null) {
            return;
        }

        BitacoraSituacionFinanciera::create([
            'matricula_oferta_id' => $matricula->id,
            'situacion_id' => $situacion->id,
            'motivo' => $motivo,
            'momento' => now(),
        ]);
    }
}
