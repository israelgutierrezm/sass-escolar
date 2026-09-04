<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Admisiones\Aspirante;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\Finanzas\Adeudo;
use App\Models\Finanzas\MetodoPago;
use App\Models\Finanzas\Pago;
use App\Models\Identidad\Usuario;
use App\Services\Caja\OperacionDeCaja;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Registra un pago y lo aplica a los adeudos que cubre.
 *
 * Todo ocurre en una transacción: un pago cobrado sin aplicar, o adeudos
 * marcados como pagados sin pago que los respalde, son las dos formas de dejar
 * una caja que no cuadra.
 *
 * El estatus del pago NO lo elige quien lo captura: lo dicta
 * `metodos_pago.requiere_confirmacion`. Un pago en ventanilla nace cobrado; una
 * transferencia nace pendiente y solo `confirmar()` la vuelve dinero. Dejarlo a
 * criterio del capturista es cómo se da por pagado un adeudo con dinero que
 * nunca llegó.
 *
 * El titular puede ser una matrícula O un aspirante. No son dos flujos: es el
 * mismo cobro con distinto dueño, porque el aspirante paga su ficha y su
 * inscripción antes de tener matrícula. Duplicar el registrador para admisiones
 * habría significado dos sitios donde arreglar la próxima regla de caja.
 */
class RegistradorPago
{
    public function __construct(private readonly OperacionDeCaja $caja) {}

    /**
     * Registra un pago y lo reparte.
     *
     * @param  array<int, int>|null  $adeudoIds  a cuáles aplicarlo, en orden. Si
     *                                           es null se cubren los más
     *                                           vencidos primero.
     */
    public function registrar(
        MatriculaOferta|Aspirante $titular,
        MetodoPago $metodo,
        float $monto,
        ?array $adeudoIds = null,
        ?string $referencia = null,
        ?string $pasarela = null,
        ?string $pasarelaTxnId = null,
    ): Pago {
        if ($monto <= 0) {
            throw new RuntimeException('El monto del pago debe ser mayor que cero.');
        }

        /*
         * El turno se RESUELVE, no se pide como parámetro.
         *
         * Pedirlo habría bastado con que un solo camino lo olvidara —el cobro
         * del portal, un reintento, una pantalla nueva— para dejar efectivo
         * fuera del arqueo, en silencio y para siempre. Resolviéndolo aquí, lo
         * que cobra la ventanilla cae en su corte y lo que entra sin persona
         * detrás (una pasarela, un comando) no cae en ninguno, que es lo
         * correcto: ese dinero no pasa por el cajón.
         */
        $quienCobra = Auth::user() instanceof Usuario ? Auth::user() : null;
        $sesion = $this->caja->sesionDe($quienCobra);

        $impedimento = $this->caja->motivoParaNoCobrar($metodo, $quienCobra);

        if ($impedimento !== null) {
            throw new RuntimeException($impedimento);
        }

        return DB::transaction(function () use (
            $titular, $metodo, $monto, $adeudoIds, $referencia, $pasarela, $pasarelaTxnId, $sesion
        ) {
            $pago = Pago::create([
                ...$this->columnaTitular($titular),
                'metodo_pago_id' => $metodo->id,
                // Nulo cuando no hay turno: es lo que distingue el dinero del
                // cajón del que entra por otro lado. Ver arriba.
                'sesion_caja_id' => $sesion?->id,
                'monto' => $monto,
                'referencia' => $referencia,
                'pasarela' => $pasarela,
                'pasarela_txn_id' => $pasarelaTxnId,
                'estatus' => $metodo->estatusInicialDePago(),
                'momento' => now(),
            ]);

            $this->aplicar($pago, $this->adeudosACubrir($titular, $adeudoIds));

            return $pago;
        });
    }

    /**
     * De quién es el dinero: de una matrícula o de un aspirante.
     *
     * La base impone que haya EXACTAMENTE uno de los dos —un CHECK sobre
     * `(matricula_oferta_id IS NOT NULL) + (aspirante_id IS NOT NULL) = 1`—,
     * así que se devuelve la pareja completa con el otro en null en vez de una
     * sola clave: escribir sólo una dejaría la anterior puesta al reutilizar el
     * arreglo y reventaría el CHECK con un error ilegible.
     *
     * @return array{matricula_oferta_id: ?int, aspirante_id: ?int}
     */
    private function columnaTitular(MatriculaOferta|Aspirante $titular): array
    {
        return $titular instanceof Aspirante
            ? ['matricula_oferta_id' => null, 'aspirante_id' => $titular->id]
            : ['matricula_oferta_id' => $titular->id, 'aspirante_id' => null];
    }

    /**
     * Confirma un pago que estaba esperando el banco o la pasarela. Es lo que
     * convierte la promesa en dinero y, con ello, liquida los adeudos que ya
     * tenía aplicados.
     */
    public function confirmar(Pago $pago): void
    {
        if ($pago->estatus === Pago::ESTATUS_COMPLETADO) {
            return;
        }

        DB::transaction(function () use ($pago) {
            $pago->update(['estatus' => Pago::ESTATUS_COMPLETADO]);

            foreach ($pago->adeudos as $adeudo) {
                $this->actualizarEstatus($adeudo);
            }
        });
    }

    /**
     * Marca un pago como fallido o reembolsado y devuelve los adeudos que
     * cubría a su estado real.
     *
     * La aplicación NO se borra: que un pago se haya intentado y rebotado es
     * parte de la historia de la cuenta, y borrarlo dejaría al alumno
     * preguntando por un cargo que aparecía cubierto la semana pasada.
     */
    public function revertir(Pago $pago, string $estatus = Pago::ESTATUS_FALLIDO, ?string $motivo = null): void
    {
        $quienDevuelve = Auth::user() instanceof Usuario ? Auth::user() : null;

        /*
         * Sólo un REEMBOLSO saca dinero del cajón.
         *
         * `fallido` es «esto nunca fue dinero» —un cobro capturado por error— y
         * no mueve billetes: anotarle una salida dejaría la caja corta por un
         * dinero que jamás entró. La diferencia entre los dos estatus ya
         * existía; aquí por fin la usa alguien.
         */
        $esReembolso = $estatus === Pago::ESTATUS_REEMBOLSADO;

        if ($esReembolso) {
            $impedimento = $this->caja->motivoParaNoDevolver($pago, $quienDevuelve);

            if ($impedimento !== null) {
                throw new RuntimeException($impedimento);
            }
        }

        DB::transaction(function () use ($pago, $estatus, $esReembolso, $quienDevuelve, $motivo) {
            $pago->update(['estatus' => $estatus]);

            if ($esReembolso) {
                $this->caja->registrarDevolucion($pago, $quienDevuelve, $motivo);
            }

            foreach ($pago->adeudos as $adeudo) {
                $this->actualizarEstatus($adeudo);
            }
        });
    }

    /**
     * Reparte el pago entre los adeudos, del más vencido al menos, hasta
     * agotarlo. Lo que sobre queda sin aplicar (un anticipo) y se ve como tal
     * en el estado de cuenta.
     *
     * @param  Collection<int, Adeudo>  $adeudos
     */
    private function aplicar(Pago $pago, $adeudos): void
    {
        $restante = (float) $pago->monto;

        foreach ($adeudos as $adeudo) {
            if ($restante <= 0) {
                break;
            }

            /*
             * El saldo se lee con la fila YA BLOQUEADA por `adeudosACubrir`, así
             * que aquí no puede haber cambiado por debajo. Antes del bloqueo,
             * dos cobros simultáneos leían el mismo y aplicaban los dos el total.
             *
             * Se probó a releer el modelo con `fresh()` y se RETIRÓ al medirlo:
             * `montoAplicado()` consulta `pago_adeudo` en cada llamada —no usa
             * una relación cargada—, así que el saldo ya es el de ahora y lo
             * único que `fresh()` volvía a traer era `monto_total`, que no
             * cambia dentro de la transacción. Era una consulta por adeudo a
             * cambio de nada, y una segunda forma de decir lo mismo es como se
             * llega a que una se quede vieja. Es la lección de
             * `$diseno->exists`.
             */
            $saldo = $adeudo->saldo();

            if ($saldo <= 0) {
                continue;
            }

            $aplicado = min($restante, $saldo);

            $pago->adeudos()->attach($adeudo->id, ['monto_aplicado' => round($aplicado, 2)]);

            $restante = round($restante - $aplicado, 2);

            $this->actualizarEstatus($adeudo->refresh());
        }
    }

    /**
     * @return Collection<int, Adeudo>
     */
    private function adeudosACubrir(MatriculaOferta|Aspirante $titular, ?array $adeudoIds)
    {
        $consulta = Adeudo::query()
            ->when($titular instanceof Aspirante,
                fn ($q) => $q->deAspirante($titular->id),
                fn ($q) => $q->deMatricula($titular->id),
            )
            ->porCobrar()
            /*
             * ── El BLOQUEO, y por qué no basta con la transacción ──────────
             *
             * `aplicar()` lee el saldo de cada adeudo y luego escribe cuánto le
             * aplica. Sin bloquear la fila, dos cajeros cobrando a la vez el
             * mismo adeudo de $1,000 leen los dos «saldo 1,000» y aplican los
             * dos $1,000: queda con $2,000 aplicados sobre un total de $1,000.
             *
             * La llave primaria de `pago_adeudo` es `(pago_id, adeudo_id)`, así
             * que impide repetir la MISMA pareja y no impide que pagos
             * DISTINTOS se pasen del total — que es justo el caso.
             *
             * Y no hay red declarativa que lo cubra: «la suma de lo aplicado no
             * pasa del total» es una condición sobre varias filas de otra
             * tabla, y MySQL no tiene restricciones de ese tipo. Así que el
             * bloqueo pesimista ES la defensa, no un refuerzo de otra.
             *
             * `lockForUpdate` serializa a las dos transacciones sobre estas
             * filas: la segunda espera, y al leer ve el saldo ya reducido.
             */
            ->lockForUpdate();

        if ($adeudoIds !== null) {
            // Se respeta el orden que eligió quien cobra: si el alumno viene a
            // pagar su titulación, no se le aplica a la colegiatura de marzo
            // porque esté más vencida.
            //
            // El BLOQUEO se toma por id ascendente y no en el orden de captura:
            // dos cobros que eligieran los mismos adeudos en orden distinto se
            // bloquearían en cruz y MySQL mataría a uno por interbloqueo. El
            // orden de APLICACIÓN sigue siendo el que eligió quien cobra.
            $adeudos = $consulta->whereIn('id', $adeudoIds)->orderBy('id')->get()->keyBy('id');

            return collect($adeudoIds)
                ->map(fn (int $id) => $adeudos->get($id))
                ->filter()
                ->values();
        }

        return $consulta->orderBy('fecha_vencimiento')->orderBy('id')->get();
    }

    /**
     * El estatus del adeudo se DERIVA de lo aplicado, no se captura. Así no
     * puede quedar un adeudo "pagado" con saldo ni uno "pendiente" ya cubierto.
     *
     * Cancelado y condonado se respetan: son decisiones administrativas y un
     * pago posterior no las revierte solo.
     */
    private function actualizarEstatus(Adeudo $adeudo): void
    {
        if (in_array($adeudo->estatus, [Adeudo::ESTATUS_CANCELADO, Adeudo::ESTATUS_CONDONADO], true)) {
            return;
        }

        $aplicado = $adeudo->montoAplicado();

        $estatus = match (true) {
            $aplicado <= 0 => Adeudo::ESTATUS_PENDIENTE,
            $aplicado >= (float) $adeudo->monto_total => Adeudo::ESTATUS_PAGADO,
            default => Adeudo::ESTATUS_PARCIAL,
        };

        if ($adeudo->estatus !== $estatus) {
            $adeudo->update(['estatus' => $estatus]);
        }
    }
}
