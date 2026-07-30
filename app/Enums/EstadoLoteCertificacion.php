<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Máquina de estados del lote de certificación.
 *
 *   borrador ──cerrar──▶ en_espera_firma ──firmar──▶ firmado
 *      ▲                        │
 *      └────────reabrir─────────┘
 *
 * `firmado` es terminal: el lote ya produjo XML sellados y no se toca. Sólo se
 * pueden agregar/quitar alumnos en `borrador`; cerrar congela el contenido para
 * que el responsable firme exactamente lo que revisó.
 */
enum EstadoLoteCertificacion: string
{
    case Borrador = 'borrador';
    case EnEsperaFirma = 'en_espera_firma';
    case Firmado = 'firmado';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Borrador => 'Borrador',
            self::EnEsperaFirma => 'En espera de firma',
            self::Firmado => 'Firmado y sellado',
        };
    }

    /** Token de color para el badge en la UI. */
    public function color(): string
    {
        return match ($this) {
            self::Borrador => 'gris',
            self::EnEsperaFirma => 'ambar',
            self::Firmado => 'verde',
        };
    }

    /** Sólo un lote en borrador admite cambiar su lista de alumnos. */
    public function admiteAlumnos(): bool
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
}
