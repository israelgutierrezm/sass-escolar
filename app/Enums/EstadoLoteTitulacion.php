<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Máquina de estados del lote de titulación.
 *
 *   borrador ──cerrar──▶ en_espera_firma ──firmar──▶ firmado ──enviar──▶ enviado
 *      ▲                        │
 *      └────────reabrir─────────┘
 *
 * `enviado` es terminal: el lote ya fue al web service de la SEP. `firmado`
 * produjo los XML sellados pero aún no se han enviado. Sólo se agregan/quitan
 * alumnos y se edita la ETAPA en `borrador`: cerrar congela el contenido para
 * que el responsable firme exactamente lo que revisó.
 */
enum EstadoLoteTitulacion: string
{
    case Borrador = 'borrador';
    case EnEsperaFirma = 'en_espera_firma';
    case Firmado = 'firmado';
    case Enviado = 'enviado';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Borrador => 'Borrador',
            self::EnEsperaFirma => 'En espera de firma',
            self::Firmado => 'Firmado y sellado',
            self::Enviado => 'Enviado al web service',
        };
    }

    /** Token de color para el badge en la UI. */
    public function color(): string
    {
        return match ($this) {
            self::Borrador => 'gris',
            self::EnEsperaFirma => 'ambar',
            self::Firmado => 'azul',
            self::Enviado => 'verde',
        };
    }

    /** Sólo un lote en borrador admite cambiar su lista de alumnos. */
    public function admiteAlumnos(): bool
    {
        return $this === self::Borrador;
    }

    /** La etapa sólo se edita mientras el lote sigue en borrador. */
    public function puedeEditarEtapa(): bool
    {
        return $this === self::Borrador;
    }

    public function puedeCerrar(): bool
    {
        return $this === self::Borrador;
    }

    public function puedeReabrir(): bool
    {
        return $this === self::EnEsperaFirma;
    }

    public function puedeFirmar(): bool
    {
        return $this === self::EnEsperaFirma;
    }

    /** Sólo un lote ya firmado (con XML sellados) puede enviarse al WS. */
    public function puedeEnviar(): bool
    {
        return $this === self::Firmado;
    }
}
