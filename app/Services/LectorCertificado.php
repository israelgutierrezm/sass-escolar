<?php

declare(strict_types=1);

namespace App\Services;

use PhpCfdi\Credentials\Certificate;
use Throwable;

/**
 * Lee un certificado (.cer, DER o PEM) y extrae lo que identifica a su titular:
 * nombre, CURP, número de serie y vigencia. Es lo que precarga el formulario del
 * responsable; el usuario solo completa cargo y título.
 *
 * En los certificados del SAT (e.firma) el campo `x500UniqueIdentifier` trae
 * "RFC CURP" separados por espacio: el RFC primero (así lo toma la propia
 * librería) y la CURP después.
 */
class LectorCertificado
{
    /**
     * @return array{titular: string, nombre: string, apellido_paterno: string, apellido_materno: string, curp: string, rfc: string, serial: string, vigencia_inicio: string, vigencia_fin: string}
     */
    public function leer(string $contenido): array
    {
        $cert = new Certificate($contenido);

        $partes = preg_split('/\s+/', trim($cert->subjectData('x500UniqueIdentifier'))) ?: [];
        $curp = $partes[1] ?? '';

        $titular = $cert->legalName();
        [$nombre, $paterno, $materno] = $this->separarNombre($titular);

        $serial = $cert->serialNumber();

        return [
            'titular' => $titular,
            'nombre' => $nombre,
            'apellido_paterno' => $paterno,
            'apellido_materno' => $materno,
            'curp' => mb_strtoupper($curp),
            'rfc' => mb_strtoupper($cert->rfc()),
            // El serial del SAT es ASCII imprimible (20 dígitos); si no, se cae al hex.
            'serial' => $serial->bytesArePrintable() ? $serial->bytes() : $serial->hexadecimal(),
            'vigencia_inicio' => $cert->validFromDateTime()->format('Y-m-d'),
            'vigencia_fin' => $cert->validToDateTime()->format('Y-m-d'),
        ];
    }

    /** True si el contenido es un certificado legible. */
    public function esValido(string $contenido): bool
    {
        try {
            new Certificate($contenido);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Separa el nombre legal en nombre(s) + dos apellidos. Heurística: los dos
     * últimos tokens son los apellidos, el resto el nombre. Queda EDITABLE en el
     * formulario porque hay apellidos compuestos que esto no adivina.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private function separarNombre(string $completo): array
    {
        $tokens = preg_split('/\s+/', trim($completo)) ?: [];
        $n = count($tokens);

        return match (true) {
            $n === 0 => ['', '', ''],
            $n === 1 => [$tokens[0], '', ''],
            $n === 2 => [$tokens[0], $tokens[1], ''],
            default => [
                implode(' ', array_slice($tokens, 0, $n - 2)),
                $tokens[$n - 2],
                $tokens[$n - 1],
            ],
        };
    }
}
