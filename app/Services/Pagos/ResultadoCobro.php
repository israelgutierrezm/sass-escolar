<?php

declare(strict_types=1);

namespace App\Services\Pagos;

/**
 * Lo que la pasarela dice que pasó con un cobro.
 *
 * Siempre sale de PREGUNTARLE a la pasarela, nunca de creerle al cuerpo de un
 * aviso ni al navegador que vuelve: los dos son texto que cualquiera puede
 * mandar, y darlos por buenos es regalar colegiaturas a quien sepa escribir una
 * petición.
 */
final class ResultadoCobro
{
    public function __construct(
        /** La intención a la que corresponde (lo que se mandó como referencia propia). */
        public readonly ?int $intencionId,
        public readonly EstadoCobro $estado,
        /** Lo que de verdad se cobró, según la pasarela. */
        public readonly ?float $monto = null,
        /** El identificador de la transacción, para poder rastrearla en el panel. */
        public readonly ?string $transaccionId = null,
        /** @var array<string, mixed> La respuesta cruda, para explicar después. */
        public readonly array $crudo = [],
    ) {}
}
