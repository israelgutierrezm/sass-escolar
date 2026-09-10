<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Finanzas\Pago;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Un pago se volvió DINERO: pasó a cobrado.
 *
 * Es el único punto por el que un cobro se confirma, venga de donde venga —la
 * ventanilla, la pasarela, un comprobante aprobado—: todos pasan por
 * `RegistradorPago::confirmar`, que lo emite DESPUÉS de que la confirmación ya
 * quedó guardada. Así «pago confirmado» es una sola señal para todos los
 * canales (R06.09), y quien la escucha —hoy, la facturación automática— actúa
 * sobre un cobro que ya es firme.
 */
class PagoConfirmado
{
    use Dispatchable;

    public function __construct(public readonly Pago $pago) {}
}
