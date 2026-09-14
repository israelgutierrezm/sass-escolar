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
        public bool $pendiente = false,
    ) {}

    public static function timbrado(string $uuid, ?string $xml = null, ?string $pdf = null): self
    {
        return new self(exito: true, uuid: $uuid, xml: $xml, pdf: $pdf);
    }

    /**
     * El PAC aceptó la cancelación. `$pendiente` dice si el SAT la dejó EN
     * PROCESO —montos que exigen que el receptor la acepte—: en ese caso el
     * comprobante SIGUE VIVO ante el SAT hasta que se acepte, así que quien
     * llama no debe darlo por cancelado ni liberar sus pagos todavía.
     */
    public static function cancelado(bool $pendiente = false): self
    {
        return new self(exito: true, pendiente: $pendiente);
    }

    public static function rechazado(string $error, ?string $codigo = null): self
    {
        return new self(exito: false, error: $error, codigo: $codigo);
    }
}
