<?php

declare(strict_types=1);

namespace App\Services\Nomina;

use App\Models\Finanzas\DatosFacturacion;
use App\Models\Finanzas\EmisorFiscal;
use App\Models\Nomina\ReciboConcepto;
use App\Models\Nomina\ReciboNomina;

/**
 * Qué le falta a un recibo para poder timbrarse.
 *
 * ── Es lo que el cliente pidió, y es lo que de verdad sirve ───────────────
 * «Encendido, que valide la información requerida para timbrar». Un PAC
 * rechazando cuarenta recibos el día de pago con códigos como `CFDI40147` es
 * inútil: hay que decir QUÉ falta, DE QUIÉN y DÓNDE se captura, antes de
 * intentarlo. Es el mismo papel que `ValidadorDec` con los certificados de la
 * SEP, que nombra la asignatura concreta a la que le falta el identificador.
 *
 * ── Los datos fiscales del empleado salen de `datos_facturacion` ──────────
 * Ahí viven ya el RFC, el régimen y el código postal de cualquier persona —es
 * la tabla que usa la facturación para el receptor— y son los mismos datos.
 * Una tabla aparte para el empleado sería una segunda verdad sobre el mismo
 * RFC.
 */
class ValidadorNomina
{
    /** Forma del RFC. El dígito verificador lo comprueba el SAT. */
    private const RFC = '/^[A-ZÑ&]{3,4}\d{6}[A-Z\d]{3}$/i';

    private const CURP = '/^[A-Z]{4}\d{6}[HM][A-Z]{5}[A-Z\d]\d$/i';

    /**
     * Todo lo que impide timbrar este recibo.
     *
     * Vacío = se puede intentar. Cada renglón dice qué falta y dónde se
     * arregla: un mensaje que sólo dice «datos incompletos» obliga a adivinar.
     *
     * @return array<int, array{falta: string, donde: string}>
     */
    public function faltantes(ReciboNomina $recibo): array
    {
        $recibo->loadMissing([
            'expediente.persona',
            'expediente.tipoContrato',
            'periodo',
            'conceptos.concepto',
        ]);

        return array_merge(
            $this->delPatron(),
            $this->delEmpleado($recibo),
            $this->delPeriodo($recibo),
            $this->deLosConceptos($recibo),
            $this->delImporte($recibo),
        );
    }

    /** @return array<int, array{falta: string, donde: string}> */
    private function delPatron(): array
    {
        $emisor = EmisorFiscal::query()->where('activo', true)->first();

        if ($emisor === null) {
            return [[
                'falta' => 'No hay ninguna razón social activa con la que timbrar.',
                'donde' => 'Finanzas → Emisores fiscales',
            ]];
        }

        $faltan = [];

        // El registro patronal ante el IMSS sólo lo pide la nómina, así que una
        // escuela que ya factura puede no tenerlo capturado.
        if (blank($emisor->registro_patronal)) {
            $faltan[] = [
                'falta' => "«{$emisor->razon_social}» no tiene registro patronal.",
                'donde' => 'Finanzas → Emisores fiscales',
            ];
        }

        if (blank($emisor->certificado_ruta) || blank($emisor->llave_ruta)) {
            $faltan[] = [
                'falta' => "«{$emisor->razon_social}» no tiene cargado su certificado de sello digital.",
                'donde' => 'Finanzas → Emisores fiscales',
            ];
        }

        return $faltan;
    }

    /** @return array<int, array{falta: string, donde: string}> */
    private function delEmpleado(ReciboNomina $recibo): array
    {
        $expediente = $recibo->expediente;
        $persona = $expediente?->persona;
        $quien = $persona?->nombreCompleto() ?? 'el empleado';
        $faltan = [];

        if (! preg_match(self::RFC, (string) $persona?->rfc)) {
            $faltan[] = ['falta' => "{$quien} no tiene RFC válido.", 'donde' => 'Su expediente de identidad'];
        }

        if (! preg_match(self::CURP, (string) $persona?->curp)) {
            $faltan[] = ['falta' => "{$quien} no tiene CURP válida.", 'donde' => 'Su expediente de identidad'];
        }

        // El NSS no lo exige el SAT para asimilados, pero sí para quien está
        // dado de alta ante el IMSS, que es el caso normal. Se pide y se dice.
        if (blank($persona?->nss)) {
            $faltan[] = ['falta' => "{$quien} no tiene NSS.", 'donde' => 'Su expediente laboral'];
        }

        if (blank($expediente?->regimen_sat)) {
            $faltan[] = [
                'falta' => "{$quien} no tiene régimen del SAT en su vínculo laboral.",
                'donde' => 'Su expediente laboral',
            ];
        }

        if (blank($expediente?->tipoContrato?->clave_sat)) {
            $faltan[] = [
                'falta' => 'El tipo de contrato «'.($expediente?->tipoContrato?->nombre ?? '—')
                    .'» no tiene clave del SAT.',
                'donde' => 'Recursos humanos → Catálogos',
            ];
        }

        /*
         * Régimen fiscal y código postal del RECEPTOR: los pide el CFDI 4.0 y
         * viven en `datos_facturacion`, que es donde ya están los de cualquier
         * persona que pide factura.
         */
        $fiscales = DatosFacturacion::query()->where('persona_id', $expediente?->persona_id)->first();

        if ($fiscales === null || blank($fiscales->regimen_fiscal) || blank($fiscales->cp)) {
            $faltan[] = [
                'falta' => "{$quien} no tiene régimen fiscal y código postal capturados.",
                'donde' => 'Finanzas → Datos de facturación de esa persona',
            ];
        }

        return $faltan;
    }

    /** @return array<int, array{falta: string, donde: string}> */
    private function delPeriodo(ReciboNomina $recibo): array
    {
        $faltan = [];

        if (blank($recibo->periodo?->periodicidad_sat)) {
            $faltan[] = [
                'falta' => 'El periodo no dice su periodicidad de pago según el SAT.',
                'donde' => 'La ficha del periodo',
            ];
        }

        if ($recibo->periodo?->fecha_pago === null) {
            $faltan[] = [
                'falta' => 'El periodo no tiene fecha de pago, y el comprobante la lleva.',
                'donde' => 'La ficha del periodo',
            ];
        }

        return $faltan;
    }

    /** @return array<int, array{falta: string, donde: string}> */
    private function deLosConceptos(ReciboNomina $recibo): array
    {
        /*
         * Cada renglón del recibo viaja al SAT con su clave de catálogo. Se
         * nombran los conceptos concretos a los que les falta —hasta cinco,
         * para que un catálogo a medio capturar no llene la pantalla—, igual
         * que `ValidadorDec` con las asignaturas.
         */
        $sinClave = $recibo->conceptos
            ->filter(fn (ReciboConcepto $r) => blank($r->concepto?->clave_sat))
            ->map(fn (ReciboConcepto $r) => $r->concepto?->nombre ?? '—')
            ->unique()
            ->values();

        if ($sinClave->isEmpty()) {
            return [];
        }

        return [[
            'falta' => 'Sin clave del SAT: '.$sinClave->take(5)->implode(', ')
                .($sinClave->count() > 5 ? ' y '.($sinClave->count() - 5).' más' : '').'.',
            'donde' => 'Recursos humanos → Catálogos de nómina',
        ]];
    }

    /** @return array<int, array{falta: string, donde: string}> */
    private function delImporte(ReciboNomina $recibo): array
    {
        // Un comprobante en ceros no lo acepta el SAT, y además significa que
        // el recibo trae una incidencia sin resolver.
        if ((float) $recibo->total_percepciones <= 0) {
            return [[
                'falta' => 'El recibo no tiene percepciones: no hay nada que timbrar.',
                'donde' => 'Revisa su sueldo y recalcula el periodo',
            ]];
        }

        return [];
    }
}
