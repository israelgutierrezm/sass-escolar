<?php

declare(strict_types=1);

namespace App\Services\Pagos;

/**
 * Lo que devuelve una pasarela cuando acepta cobrar: a dónde mandar a quien
 * paga y con qué nombre conoce ella este cobro.
 */
final class CobroIniciado
{
    public function __construct(
        /** A dónde se envía el navegador para pagar. */
        public readonly string $url,
        /** Cómo llama la pasarela a este cobro (su identificador). */
        public readonly string $referenciaExterna,
        /** @var array<string, mixed> */
        public readonly array $crudo = [],
    ) {}
}
