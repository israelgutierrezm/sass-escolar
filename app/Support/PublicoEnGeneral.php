<?php

declare(strict_types=1);

namespace App\Support;

/**
 * El receptor «público en general» de un CFDI global. Es un PRESET del SAT, no
 * un perfil editable: el RFC genérico, la razón social, el régimen y el uso son
 * los que el SAT fija para la factura global, y no se pueden inventar (R06.07).
 * Lo único que viene del emisor es el CP —su lugar de expedición—.
 */
final class PublicoEnGeneral
{
    public const RFC = 'XAXX010101000';

    public const RAZON_SOCIAL = 'PÚBLICO EN GENERAL';

    /** 616 · Sin obligaciones fiscales. */
    public const REGIMEN = '616';

    /** S01 · Sin efectos fiscales. */
    public const USO_CFDI = 'S01';

    /**
     * El receptor genérico, con el CP (lugar de expedición) del emisor.
     *
     * @return array<string, string>
     */
    public static function receptor(string $cpEmisor): array
    {
        return [
            'rfc' => self::RFC,
            'razon_social' => self::RAZON_SOCIAL,
            'uso_cfdi' => self::USO_CFDI,
            'regimen_fiscal' => self::REGIMEN,
            'cp' => $cpEmisor,
        ];
    }
}
