<?php

declare(strict_types=1);

namespace App\Services\Cfdi;

/**
 * Lo que devuelve el PAC. Un rechazo NO es una excepción: el SAT rechazando un
 * comprobante es una respuesta normal del trámite —RFC inexistente, régimen
 * que no corresponde al uso, certificado vencido— y hay que mostrársela al
 * usuario tal cual, no convertirla en un error 500.
 *
 * Se reservan las excepciones para lo que sí conviene reintentar: que el PAC
 * no conteste.
 */
final readonly class ResultadoTimbrado
{
    private function __construct(
        public bool $exito,
        public ?string $uuid = null,
        public ?string $xml = null,
        public ?string $pdf = null,
        public ?string $error = null,
        public ?string $codigo = null,
    ) {}

    public static function timbrado(string $uuid, ?string $xml = null, ?string $pdf = null): self
    {
        return new self(exito: true, uuid: $uuid, xml: $xml, pdf: $pdf);
    }

    public static function cancelado(): self
    {
        return new self(exito: true);
    }

    public static function rechazado(string $error, ?string $codigo = null): self
    {
        return new self(exito: false, error: $error, codigo: $codigo);
    }
}
