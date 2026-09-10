<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\PagoConfirmado;
use App\Services\Finanzas\FacturacionAutomatica;

/**
 * Al confirmarse un pago, factura si la escuela lo tiene automático.
 *
 * La decisión vive en `FacturacionAutomatica`, no aquí: el oyente sólo conecta
 * el evento con la política. Laravel lo descubre solo por el tipo del `handle`.
 */
class FacturarPagoConfirmado
{
    public function __construct(private readonly FacturacionAutomatica $automatica) {}

    public function handle(PagoConfirmado $evento): void
    {
        $this->automatica->alConfirmar($evento->pago);
    }
}
