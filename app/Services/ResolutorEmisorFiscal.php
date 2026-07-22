<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Admisiones\MatriculaOferta;
use App\Models\Finanzas\EmisorAsignacion;
use App\Models\Finanzas\EmisorFiscal;
use RuntimeException;

/**
 * Con qué razón social se le factura a una matrícula.
 *
 * Gana la asignación MÁS ESPECÍFICA: carrera → nivel de estudios → global. Es
 * el mismo criterio de `ResolutorPlanCobro`, y por la misma razón: la escuela
 * dice "todo con la razón social A, salvo posgrado, que va con la B" sin tener
 * que repetir la A en cada una de sus veinte carreras.
 *
 * Cuando NO hay ningún emisor dado de alta se cae a `config('cfdi.emisor')`,
 * que es el emisor único que existía antes de esta feature. Es compatibilidad
 * hacia atrás, no un respaldo permanente: en cuanto la escuela da de alta el
 * primero, la configuración deja de usarse. Distinguir los dos casos importa —
 * "no hay ninguno configurado" es una instalación que aún no llega aquí; "hay
 * varios y ninguno aplica a esta carrera" es un error que hay que gritar, no
 * tapar con un emisor por omisión que facturaría a nombre equivocado.
 */
class ResolutorEmisorFiscal
{
    /** De más específico a más general. */
    private const PRECEDENCIA = [
        EmisorAsignacion::APLICA_CARRERA,
        EmisorAsignacion::APLICA_NIVEL,
        EmisorAsignacion::APLICA_GLOBAL,
    ];

    public function para(MatriculaOferta $matricula): ?EmisorFiscal
    {
        $matricula->loadMissing('oferta.carrera');
        $carrera = $matricula->oferta?->carrera;

        $identificador = [
            EmisorAsignacion::APLICA_CARRERA => $carrera?->id,
            EmisorAsignacion::APLICA_NIVEL => $carrera?->nivel_estudios_id,
            EmisorAsignacion::APLICA_GLOBAL => null,
        ];

        foreach (self::PRECEDENCIA as $tipo) {
            $id = $identificador[$tipo];

            // Sin carrera o sin nivel no se puede buscar por ese eje; se salta
            // al siguiente en vez de traer cualquier asignación de ese tipo.
            if ($tipo !== EmisorAsignacion::APLICA_GLOBAL && $id === null) {
                continue;
            }

            $consulta = EmisorAsignacion::query()
                ->where('aplica_a_tipo', $tipo)
                ->whereHas('emisor', fn ($q) => $q->activos());

            $consulta = $id === null
                ? $consulta->whereNull('aplica_a_id')
                : $consulta->where('aplica_a_id', $id);

            $asignacion = $consulta->orderByDesc('id')->first();

            if ($asignacion !== null) {
                return $asignacion->emisor;
            }
        }

        return null;
    }

    /**
     * Los datos del emisor listos para copiarse a la factura, o el error que
     * hay que mostrarle a quien intenta facturar.
     *
     * @return array{emisor_id: ?int, emisor_rfc: string, emisor_razon_social: string, emisor_regimen_fiscal: string, emisor_cp: string}
     *
     * @throws RuntimeException si no hay con qué emitir
     */
    public function datosPara(MatriculaOferta $matricula): array
    {
        $emisor = $this->para($matricula);

        if ($emisor !== null) {
            return [
                'emisor_id' => $emisor->id,
                'emisor_rfc' => $emisor->rfc,
                'emisor_razon_social' => $emisor->razon_social,
                'emisor_regimen_fiscal' => $emisor->regimen_fiscal,
                'emisor_cp' => $emisor->cp,
            ];
        }

        // Hay razones sociales dadas de alta pero ninguna cubre esta carrera.
        // Es una configuración incompleta y hay que decirlo: facturar con la
        // primera que aparezca emitiría el comprobante a nombre equivocado, y
        // eso no se corrige con un UPDATE sino cancelando ante el SAT.
        if (EmisorFiscal::query()->activos()->exists()) {
            $carrera = $matricula->oferta?->carrera?->nombre ?? 'esta carrera';

            throw new RuntimeException(
                "No hay razón social asignada para {$carrera}. "
                .'Asígnale una en Finanzas → Razones sociales, o agrega una asignación global.'
            );
        }

        // Ninguna dada de alta todavía: se usa el emisor único del .env.
        $config = (array) config('cfdi.emisor');

        if (($config['rfc'] ?? null) === null) {
            throw new RuntimeException(
                'No hay ninguna razón social configurada. Da de alta la de la escuela '
                .'en Finanzas → Razones sociales antes de facturar.'
            );
        }

        return [
            'emisor_id' => null,
            'emisor_rfc' => (string) $config['rfc'],
            'emisor_razon_social' => (string) ($config['razon_social'] ?? ''),
            'emisor_regimen_fiscal' => (string) ($config['regimen_fiscal'] ?? '601'),
            'emisor_cp' => (string) ($config['cp'] ?? ''),
        ];
    }
}
