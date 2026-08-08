<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\AvisoParaElUsuario;
use App\Models\Finanzas\ComprobantePago;
use App\Models\Finanzas\MetodoPago;
use App\Models\Identidad\Usuario;
use Illuminate\Support\Facades\DB;

/**
 * Qué pasa cuando alguien revisa un comprobante de transferencia.
 *
 * ── Aprobar es cobrar ──────────────────────────────────────────────────────
 * Hasta aquí sólo había una imagen. Al aprobar nace el `pago` y los cargos
 * quedan liquidados, así que este es el momento en que el dinero entra en la
 * caja de la escuela — con la diferencia de que quien lo confirma no es un
 * banco, es una persona mirando un archivo.
 *
 * ── Se bloquea, como la conciliación de las pasarelas ──────────────────────
 * Dos personas pueden tener la cola abierta a la vez y aprobar el mismo
 * comprobante. Sin bloqueo eso son dos pagos por un solo depósito, y el alumno
 * acaba con saldo a favor que nadie transfirió. Mismo problema y misma
 * solución que en `CobroEnLinea::conciliar`.
 *
 * ── Rechazar exige motivo ──────────────────────────────────────────────────
 * No por formalidad: un comprobante devuelto sin explicación obliga a quien
 * pagó a adivinar qué estuvo mal —el monto, la fecha, la imagen ilegible— y
 * acaba en una llamada a la escuela.
 */
class RevisorDeComprobantes
{
    public function __construct(private readonly RegistradorPago $registrador) {}

    /**
     * Da por bueno el comprobante: registra el pago y liquida los cargos.
     *
     * @param  float|null  $monto  Lo que de verdad se depositó, si al revisar se
     *                             ve que no era lo declarado. Sin esto se usa lo
     *                             que dijo quien subió el comprobante.
     */
    public function aprobar(ComprobantePago $comprobante, Usuario $revisor, ?float $monto = null): ComprobantePago
    {
        return DB::transaction(function () use ($comprobante, $revisor, $monto) {
            $comprobante = ComprobantePago::query()
                ->whereKey($comprobante->id)
                ->lockForUpdate()
                ->firstOrFail();

            AvisoParaElUsuario::si(
                $comprobante->estaResuelto(),
                422,
                'Ese comprobante ya lo revisó alguien más.',
            );

            $titular = $comprobante->titular();

            AvisoParaElUsuario::aMenosQue(
                $titular !== null,
                422,
                'Ese comprobante se quedó sin titular: no hay a quién abonarle el pago.',
            );

            $pago = $this->registrador->registrar(
                $titular,
                $this->metodo(),
                $monto ?? (float) $comprobante->monto,
                // Los cargos que eligió quien pagó. Si alguno se liquidó por
                // otra vía, el registrador reparte lo que quede.
                $comprobante->adeudo_ids ?: null,
                referencia: $comprobante->referencia ?: 'Transferencia',
            );

            /*
             * El método nace pidiendo confirmación —una transferencia no es
             * dinero hasta que alguien la ve— y este es justo ese momento: la
             * persona que aprueba ES la confirmación.
             */
            $this->registrador->confirmar($pago);

            $comprobante->update([
                'estado' => ComprobantePago::APROBADO,
                'pago_id' => $pago->id,
                'revisado_por' => $revisor->id,
                'revisado_en' => now(),
                // Si se corrigió el monto al revisar, el comprobante guarda el
                // real: es lo que después hay que cuadrar contra el banco.
                'monto' => $monto ?? $comprobante->monto,
            ]);

            return $comprobante->fresh();
        });
    }

    /** Lo devuelve con un motivo. No toca ningún cargo. */
    public function rechazar(ComprobantePago $comprobante, Usuario $revisor, string $motivo): ComprobantePago
    {
        return DB::transaction(function () use ($comprobante, $revisor, $motivo) {
            $comprobante = ComprobantePago::query()
                ->whereKey($comprobante->id)
                ->lockForUpdate()
                ->firstOrFail();

            AvisoParaElUsuario::si(
                $comprobante->estaResuelto(),
                422,
                'Ese comprobante ya lo revisó alguien más.',
            );

            $comprobante->update([
                'estado' => ComprobantePago::RECHAZADO,
                'motivo_rechazo' => $motivo,
                'revisado_por' => $revisor->id,
                'revisado_en' => now(),
            ]);

            return $comprobante->fresh();
        });
    }

    /**
     * Con qué método entra este dinero.
     *
     * Se reutiliza el de transferencia que la escuela ya tiene sembrado —es
     * literalmente lo que ocurrió— en vez de inventar uno nuevo, para que la
     * caja no acabe con dos conceptos que significan lo mismo.
     */
    private function metodo(): MetodoPago
    {
        return MetodoPago::firstOrCreate(
            ['clave' => 'transferencia'],
            [
                'nombre' => 'Transferencia (SPEI)',
                'requiere_confirmacion' => true,
                'activo' => true,
            ],
        );
    }
}
