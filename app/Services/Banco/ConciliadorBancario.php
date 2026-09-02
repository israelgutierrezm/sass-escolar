<?php

declare(strict_types=1);

namespace App\Services\Banco;

use App\Exceptions\AvisoParaElUsuario;
use App\Models\Finanzas\ConciliacionPartida;
use App\Models\Finanzas\DepositoCaja;
use App\Models\Finanzas\EstadoCuentaBancaria;
use App\Models\Finanzas\MovimientoBancario;
use App\Models\Finanzas\Pago;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Casa los renglones del banco contra los movimientos del sistema.
 *
 * ── Qué entra al banco, y por dónde ────────────────────────────────────────
 * El efectivo NO llega al banco pago por pago: llega junto, en el DEPÓSITO del
 * turno de caja. Por eso los pagos que `afecta_caja` marca quedan fuera de los
 * candidatos —buscar en el banco un cobro de $300 en efectivo es buscar algo
 * que nunca estuvo ahí— y lo que se busca en su lugar es su depósito. Lo demás
 * —transferencia, tarjeta, pasarela— sí llega directo.
 *
 * ── El pareo automático sólo cuando NO hay duda ────────────────────────────
 * Se aplica solo cuando exactamente UN candidato casa por referencia Y por
 * importe. Con dos candidatos posibles se propone y decide una persona: un
 * pareo automático equivocado no se ve —la pantalla queda en verde— y esconde
 * dinero real, que es peor que dejar el renglón sin casar.
 *
 * ── El importe aplicado se DERIVA, no se teclea ────────────────────────────
 * Es el del movimiento del sistema. Tecleándolo, cualquier diferencia se puede
 * tapar escribiendo el número que cuadra, y entonces «conciliado» deja de
 * significar nada. A cambio, un pago que el banco partiera en dos renglones no
 * se puede conciliar: es raro, y se prefiere no poder a poder mentir.
 *
 * ── Lo que no casa no se esconde: se CLASIFICA ─────────────────────────────
 * Una comisión, unos intereses o un traspaso entre cuentas propias no tienen
 * con qué casar, y una liquidación de pasarela llega NETA —doce cobros por
 * 12,000 contra un renglón de 11,700—. En los dos casos la clasificación
 * explica lo que sobra o falta frente a lo conciliado. Sin ella la lista de
 * pendientes nunca llegaría a cero, y una cola que nunca baja enseña a no
 * mirarla.
 */
class ConciliadorBancario
{
    /** Días a los lados de la fecha del banco donde se busca un candidato. */
    private const VENTANA_DIAS = 5;

    /**
     * Movimientos del sistema que podrían ser este renglón.
     *
     * @return array<int, array<string, mixed>>
     */
    public function candidatos(MovimientoBancario $movimiento): array
    {
        if (! $movimiento->esEntrada()) {
            // Una salida del banco no puede ser un cobro. Ofrecer candidatos
            // aquí invitaría a casar un cargo contra un pago.
            return [];
        }

        $desde = $movimiento->fecha->copy()->subDays(self::VENTANA_DIAS)->toDateString();
        $hasta = $movimiento->fecha->copy()->addDays(self::VENTANA_DIAS)->toDateString();

        return collect()
            ->merge($this->pagosCandidatos($movimiento, $desde, $hasta))
            ->merge($this->depositosCandidatos($movimiento, $desde, $hasta))
            ->sortByDesc('puntaje')
            ->take(20)
            ->values()
            ->all();
    }

    /**
     * Casa lo que no admite duda de todo un estado de cuenta.
     *
     * @return array{casados: int, ambiguos: int}
     */
    public function conciliarAutomatico(EstadoCuentaBancaria $estado): array
    {
        $casados = 0;
        $ambiguos = 0;

        $pendientes = MovimientoBancario::query()
            ->where('estado_cuenta_id', $estado->id)
            ->entradas()
            ->sinResolver()
            ->get();

        foreach ($pendientes as $movimiento) {
            $seguros = array_values(array_filter(
                $this->candidatos($movimiento),
                fn (array $c) => $c['seguro'],
            ));

            if (count($seguros) !== 1) {
                $ambiguos += count($seguros) > 1 ? 1 : 0;

                continue;
            }

            $this->conciliar($movimiento, [$seguros[0]['clave']], automatica: true);
            $casados++;
        }

        return ['casados' => $casados, 'ambiguos' => $ambiguos];
    }

    /**
     * Ata este renglón a uno o varios movimientos del sistema.
     *
     * Las claves llegan como «pago:12» o «deposito:3»: un renglón casa con las
     * dos clases y con VARIOS a la vez —una liquidación de pasarela son doce
     * cobros en una sola línea—.
     *
     * @param  array<int, string>  $claves
     */
    public function conciliar(MovimientoBancario $movimiento, array $claves, bool $automatica = false): int
    {
        AvisoParaElUsuario::aMenosQue(
            $movimiento->esEntrada(),
            422,
            'Ese renglón es una salida del banco: no puede ser un cobro. Clasifícalo.',
        );

        AvisoParaElUsuario::aMenosQue($claves !== [], 422, 'No elegiste ningún movimiento.');

        return DB::transaction(function () use ($movimiento, $claves, $automatica) {
            $atados = 0;

            foreach ($claves as $clave) {
                [$tipo, $id] = $this->partir($clave);

                $partida = [
                    'movimiento_bancario_id' => $movimiento->id,
                    'automatica' => $automatica,
                    'pago_id' => null,
                    'deposito_caja_id' => null,
                ];

                if ($tipo === 'pago') {
                    $pago = Pago::query()->findOrFail($id);

                    AvisoParaElUsuario::aMenosQue(
                        $pago->estatus === Pago::ESTATUS_COMPLETADO,
                        422,
                        "El pago #{$pago->id} no está cobrado: no debería haber llegado al banco.",
                    );

                    $partida['pago_id'] = $pago->id;
                    $partida['monto_aplicado'] = (float) $pago->monto;
                } else {
                    $deposito = DepositoCaja::query()->findOrFail($id);
                    $partida['deposito_caja_id'] = $deposito->id;
                    $partida['monto_aplicado'] = (float) $deposito->monto;
                }

                try {
                    ConciliacionPartida::create($partida);
                    $atados++;
                } catch (UniqueConstraintViolationException) {
                    /*
                     * Ese movimiento ya estaba conciliado contra OTRO renglón.
                     * El único de la base es lo que impide que un mismo pago
                     * cuadre dos líneas del banco y esconda un faltante; aquí
                     * se traduce a un mensaje en vez de a un 500.
                     */
                    AvisoParaElUsuario::si(
                        true,
                        422,
                        'Uno de los movimientos que elegiste ya estaba conciliado con otro renglón del banco.',
                    );
                }
            }

            return $atados;
        });
    }

    /** Deshace un pareo. La partida se borra de verdad: ver la nota del único. */
    public function desconciliar(ConciliacionPartida $partida): void
    {
        /*
         * `forceDelete` y no baja lógica, por lo mismo que las colocaciones de
         * la bolsa: el único es sobre `pago_id` a secas y MySQL no distingue
         * una fila dada de baja de una viva, así que deshacer dejaría ese pago
         * sin poder conciliarse NUNCA más — y «me equivoqué de renglón, lo
         * deshago y lo vuelvo a casar» es exactamente lo que alguien va a
         * hacer.
         */
        $partida->forceDelete();
    }

    /**
     * Declara qué es lo que este renglón tiene de más —o de menos— frente a lo
     * conciliado.
     */
    public function clasificar(MovimientoBancario $movimiento, ?string $clasificacion, ?string $nota): void
    {
        AvisoParaElUsuario::si(
            $clasificacion !== null && ! array_key_exists($clasificacion, MovimientoBancario::clasificaciones()),
            422,
            'Esa clasificación no existe.',
        );

        AvisoParaElUsuario::si(
            $clasificacion === MovimientoBancario::OTRO && trim((string) $nota) === '',
            422,
            'Si es «otro», escribe qué es: sin la razón, dentro de un año nadie podrá explicar por qué este renglón se dio por bueno.',
        );

        $movimiento->update([
            'clasificacion' => $clasificacion,
            'nota' => $nota !== null && trim($nota) !== '' ? trim($nota) : null,
        ]);
    }

    /**
     * Las dos listas por las que existe todo esto.
     *
     * @return array{
     *     sin_registrar: Collection<int, MovimientoBancario>,
     *     sin_llegar: array<int, array<string, mixed>>,
     *     total_sin_registrar: float,
     *     total_sin_llegar: float
     * }
     */
    public function panorama(EstadoCuentaBancaria $estado): array
    {
        // 1. Entró al banco y nadie lo registró: se le está cobrando a alguien
        //    un adeudo que ya pagó.
        $sinRegistrar = MovimientoBancario::query()
            ->where('estado_cuenta_id', $estado->id)
            ->entradas()
            ->sinResolver()
            ->orderBy('fecha')
            ->get();

        // 2. Se registró el cobro y el dinero nunca llegó. Aquí es donde
        //    aparece un comprobante aprobado sobre una imagen repetida, o un
        //    depósito de caja que se capturó y no se hizo.
        $sinLlegar = collect()
            ->merge($this->pagosSinConciliar($estado))
            ->merge($this->depositosSinConciliar($estado))
            ->sortBy('fecha')
            ->values()
            ->all();

        return [
            'sin_registrar' => $sinRegistrar,
            'sin_llegar' => $sinLlegar,
            'total_sin_registrar' => round((float) $sinRegistrar->sum(fn (MovimientoBancario $m) => $m->pendiente()), 2),
            'total_sin_llegar' => round(array_sum(array_column($sinLlegar, 'monto')), 2),
        ];
    }

    /** @return Collection<int, array<string, mixed>> */
    private function pagosCandidatos(MovimientoBancario $movimiento, string $desde, string $hasta): Collection
    {
        return $this->pagosDelBanco()
            ->whereBetween('momento', [$desde.' 00:00:00', $hasta.' 23:59:59'])
            ->whereNotIn('id', ConciliacionPartida::query()->whereNotNull('pago_id')->select('pago_id'))
            ->with(['metodoPago:id,clave,nombre'])
            ->limit(60)
            ->get()
            ->map(fn (Pago $p) => $this->tarjeta(
                'pago:'.$p->id,
                'Pago #'.$p->id.' · '.($p->metodoPago?->nombre ?? '—'),
                (float) $p->monto,
                $p->momento?->toDateString() ?? '',
                $p->referencia,
                $movimiento,
            ));
    }

    /** @return Collection<int, array<string, mixed>> */
    private function depositosCandidatos(MovimientoBancario $movimiento, string $desde, string $hasta): Collection
    {
        return DepositoCaja::query()
            ->where('cuenta_bancaria_id', $movimiento->cuenta_bancaria_id)
            ->whereBetween('fecha', [$desde, $hasta])
            ->whereNotIn('id', ConciliacionPartida::query()->whereNotNull('deposito_caja_id')->select('deposito_caja_id'))
            ->limit(60)
            ->get()
            ->map(fn (DepositoCaja $d) => $this->tarjeta(
                'deposito:'.$d->id,
                'Depósito de caja #'.$d->id,
                (float) $d->monto,
                $d->fecha?->toDateString() ?? '',
                $d->referencia,
                $movimiento,
            ));
    }

    /**
     * Los cobros que deberían estar en el banco: los que NO pasaron por el
     * cajón. Ver la nota de arriba.
     */
    private function pagosDelBanco()
    {
        return Pago::query()
            ->where('estatus', Pago::ESTATUS_COMPLETADO)
            ->whereHas('metodoPago', fn ($q) => $q->where('afecta_caja', false));
    }

    /** @return array<int, array<string, mixed>> */
    private function pagosSinConciliar(EstadoCuentaBancaria $estado): array
    {
        return $this->pagosDelBanco()
            ->whereBetween('momento', [
                $estado->periodo_inicio->toDateString().' 00:00:00',
                $estado->periodo_fin->toDateString().' 23:59:59',
            ])
            ->whereNotIn('id', ConciliacionPartida::query()->whereNotNull('pago_id')->select('pago_id'))
            ->with('metodoPago:id,nombre')
            ->get()
            ->map(fn (Pago $p) => [
                'clave' => 'pago:'.$p->id,
                'que' => 'Pago #'.$p->id.' · '.($p->metodoPago?->nombre ?? '—'),
                'monto' => (float) $p->monto,
                'fecha' => $p->momento?->toDateString(),
                'referencia' => $p->referencia,
            ])
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function depositosSinConciliar(EstadoCuentaBancaria $estado): array
    {
        return DepositoCaja::query()
            ->where('cuenta_bancaria_id', $estado->cuenta_bancaria_id)
            ->whereBetween('fecha', [$estado->periodo_inicio->toDateString(), $estado->periodo_fin->toDateString()])
            ->whereNotIn('id', ConciliacionPartida::query()->whereNotNull('deposito_caja_id')->select('deposito_caja_id'))
            ->get()
            ->map(fn (DepositoCaja $d) => [
                'clave' => 'deposito:'.$d->id,
                'que' => 'Depósito de caja #'.$d->id,
                'monto' => (float) $d->monto,
                'fecha' => $d->fecha?->toDateString(),
                'referencia' => $d->referencia,
            ])
            ->all();
    }

    /**
     * Un candidato con su puntaje y si es SEGURO.
     *
     * Seguro = coinciden la referencia Y el importe. Con eso, y sólo si es el
     * único, se casa solo. Todo lo demás es una propuesta ordenada.
     *
     * @return array<string, mixed>
     */
    private function tarjeta(
        string $clave,
        string $que,
        float $monto,
        string $fecha,
        ?string $referencia,
        MovimientoBancario $movimiento,
    ): array {
        $mismoImporte = abs($monto - (float) $movimiento->monto) < 0.005;
        $mismaReferencia = $this->referenciasCasan($referencia, $movimiento->referencia, $movimiento->descripcion);

        $puntaje = ($mismoImporte ? 60 : 0)
            + ($mismaReferencia ? 40 : 0)
            + max(0, 10 - abs(strtotime($fecha) - strtotime($movimiento->fecha->toDateString())) / 86400);

        return [
            'clave' => $clave,
            'que' => $que,
            'monto' => $monto,
            'fecha' => $fecha,
            'referencia' => $referencia,
            'mismo_importe' => $mismoImporte,
            'misma_referencia' => $mismaReferencia,
            'seguro' => $mismoImporte && $mismaReferencia,
            'puntaje' => round($puntaje, 2),
        ];
    }

    /**
     * ¿La referencia nuestra aparece en lo que dice el banco?
     *
     * Se busca también DENTRO del concepto: varios bancos no traen columna de
     * referencia y la meten en la descripción («SPEI RECIBIDO ... REF 4471»).
     * Se exige un mínimo de cuatro caracteres porque una referencia de tres
     * dígitos aparece por casualidad en cualquier concepto largo, y un pareo
     * automático equivocado es peor que ninguno.
     */
    private function referenciasCasan(?string $nuestra, ?string $delBanco, string $concepto): bool
    {
        $n = $this->limpiar($nuestra);

        if (mb_strlen($n) < 4) {
            return false;
        }

        return str_contains($this->limpiar($delBanco), $n)
            || str_contains($this->limpiar($concepto), $n);
    }

    private function limpiar(?string $texto): string
    {
        return mb_strtolower(preg_replace('/[^A-Za-z0-9]/', '', (string) $texto) ?? '');
    }

    /** @return array{0: string, 1: int} */
    private function partir(string $clave): array
    {
        $partes = explode(':', $clave, 2);

        AvisoParaElUsuario::si(
            count($partes) !== 2 || ! in_array($partes[0], ['pago', 'deposito'], true) || ! ctype_digit($partes[1]),
            422,
            'Referencia de movimiento inválida.',
        );

        return [$partes[0], (int) $partes[1]];
    }
}
