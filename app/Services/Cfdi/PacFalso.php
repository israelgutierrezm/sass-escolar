<?php

declare(strict_types=1);

namespace App\Services\Cfdi;

use App\Models\Finanzas\Factura;
use Illuminate\Support\Str;

/**
 * PAC de mentiras, para desarrollo y para la suite.
 *
 * Permite ejercer el flujo COMPLETO —encolar, timbrar, guardar el UUID,
 * cancelar, sustituir— sin mandar nada al SAT. Es lo que hace que el motor de
 * facturación se pueda probar antes de que la escuela contrate un PAC.
 *
 * Valida lo mismo que rechazaría un PAC de verdad en su primera revisión: RFC
 * con forma válida, total mayor que cero y al menos un renglón. Sin esas
 * comprobaciones el modo falso diría que sí a facturas que en producción
 * rebotarían, y el flujo de error nunca se ejercitaría en desarrollo, que es
 * justo cuando conviene verlo.
 */
class PacFalso implements Pac
{
    /**
     * RFC de persona física (13) o moral (12). No valida el dígito
     * verificador: eso lo hace el SAT y aquí solo se busca atajar el dedazo.
     */
    private const RFC = '/^[A-ZÑ&]{3,4}\d{6}[A-Z\d]{3}$/i';

    public function nombre(): string
    {
        return 'falso';
    }

    public function timbrar(Factura $factura): ResultadoTimbrado
    {
        if (! preg_match(self::RFC, (string) $factura->receptor_rfc)) {
            return ResultadoTimbrado::rechazado(
                "El RFC del receptor no tiene forma válida: {$factura->receptor_rfc}.",
                'CFDI40102',
            );
        }

        if ((float) $factura->total <= 0) {
            return ResultadoTimbrado::rechazado('El total del comprobante debe ser mayor que cero.', 'CFDI40105');
        }

        if ($factura->conceptos()->count() === 0) {
            return ResultadoTimbrado::rechazado('El comprobante no tiene conceptos.', 'CFDI40110');
        }

        // Un UUID de verdad lo asigna el SAT; aquí basta con que sea único y
        // con la forma correcta para que el resto del sistema lo trate igual.
        return ResultadoTimbrado::timbrado(
            strtoupper((string) Str::uuid()),
            xml: '<?xml version="1.0" encoding="UTF-8"?><!-- comprobante de prueba, sin valor fiscal -->',
        );
    }

    public function cancelar(Factura $factura, string $motivo, ?string $uuidSustituta = null): ResultadoTimbrado
    {
        if ($factura->uuid === null) {
            return ResultadoTimbrado::rechazado('No se puede cancelar un comprobante sin folio fiscal.');
        }

        // El SAT exige la sustituta cuando el motivo es 01. Es el error que
        // más se comete al cancelar, así que se atrapa aquí también.
        if ($motivo === Factura::MOTIVO_CON_RELACION && $uuidSustituta === null) {
            return ResultadoTimbrado::rechazado(
                'El motivo 01 exige el folio fiscal del comprobante que sustituye.',
                'CFDI33133',
            );
        }

        return ResultadoTimbrado::cancelado();
    }
}
