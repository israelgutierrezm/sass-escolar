<?php

declare(strict_types=1);

namespace App\Services\Facturacion;

use RuntimeException;

/**
 * Rechazo de Facturapi/SAT: la operación llegó y fue RECHAZADA (RFC inválido,
 * régimen que no corresponde, etc.). NO es una falla de comunicación: no se
 * reintenta, se le muestra al usuario. Los errores de red usan otras
 * excepciones para que la cola sí reintente.
 */
class FacturapiRechazo extends RuntimeException
{
    public function __construct(string $mensaje, public readonly ?string $codigo = null)
    {
        parent::__construct($mensaje);
    }
}
