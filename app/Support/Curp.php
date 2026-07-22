<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;

/**
 * Lectura y validación de la CURP.
 *
 * La CURP no es una cadena opaca: dentro trae la fecha de nacimiento, el sexo y
 * la entidad de nacimiento, y termina en un DÍGITO VERIFICADOR que permite
 * saber si está bien escrita sin consultar a RENAPO. Aprovecharlo es la
 * diferencia entre recapturar tres campos a mano —con sus erratas— y que el
 * formulario se llene solo y además avise cuando la CURP viene mal.
 *
 * Se usa como red de seguridad, no como autoridad: lo que extrae queda
 * EDITABLE. Hay CURP mal emitidas y personas cuya acta corrige lo que la CURP
 * dice; el sistema no debe pelearse con el documento que el interesado trae.
 *
 * `EXTRANJERO` no se guarda aquí. Ver `esMarcaDeExtranjero()`.
 */
final class Curp
{
    /**
     * Lo que el interesado teclea cuando NO tiene CURP.
     *
     * Nunca llega a la columna `personas.curp`: es UNIQUE, así que guardar el
     * literal permitiría exactamente UN extranjero en toda la escuela y el
     * segundo chocaría con un error incomprensible. Se traduce a curp = null
     * más entidad de nacimiento «Nacido en el Extranjero».
     */
    public const MARCA_EXTRANJERO = 'EXTRANJERO';

    /** Alfabeto del dígito verificador. Incluye la Ñ, y el orden importa. */
    private const ALFABETO = '0123456789ABCDEFGHIJKLMNÑOPQRSTUVWXYZ';

    private function __construct(
        public readonly string $valor,
        public readonly ?DateTimeImmutable $fechaNacimiento,
        /** 'H' o 'M', tal como la CURP lo codifica. */
        public readonly string $claveSexo,
        /** Clave de dos letras de la entidad: AS, BC… o NE para nacido fuera. */
        public readonly string $claveEntidad,
    ) {}

    /** ¿El usuario escribió la marca de «no tengo CURP»? */
    public static function esMarcaDeExtranjero(?string $texto): bool
    {
        return self::normalizar($texto) === self::MARCA_EXTRANJERO;
    }

    public static function normalizar(?string $texto): string
    {
        return mb_strtoupper(trim((string) $texto));
    }

    /**
     * Devuelve la CURP leída, o null si no es una CURP válida.
     *
     * Válida quiere decir tres cosas a la vez: forma correcta, fecha que existe
     * de verdad —`290230` pasa el patrón y no es un día— y dígito verificador
     * que cuadra.
     */
    public static function leer(?string $texto): ?self
    {
        $curp = self::normalizar($texto);

        if (! preg_match('/^[A-Z][AEIOUX][A-Z]{2}\d{6}[HM][A-Z]{5}[0-9A-Z]\d$/', $curp)) {
            return null;
        }

        if (! self::digitoCorrecto($curp)) {
            return null;
        }

        $fecha = self::fechaDe($curp);

        return $fecha === null
            ? null
            : new self($curp, $fecha, $curp[10], substr($curp, 11, 2));
    }

    /** Solo valida; no interesa lo que trae dentro. */
    public static function esValida(?string $texto): bool
    {
        return self::leer($texto) !== null;
    }

    /**
     * El siglo NO está escrito: se deduce de la posición 17 (índice 16), la
     * homoclave. Es un DÍGITO para quien nació antes del 2000 y una LETRA para
     * quien nació después. Sin esta regla, un alumno de 2005 se registraría
     * como nacido en 1905.
     */
    private static function fechaDe(string $curp): ?DateTimeImmutable
    {
        $siglo = ctype_digit($curp[16]) ? '19' : '20';

        $anio = (int) ($siglo.substr($curp, 4, 2));
        $mes = (int) substr($curp, 6, 2);
        $dia = (int) substr($curp, 8, 2);

        if (! checkdate($mes, $dia, $anio)) {
            return null;
        }

        return new DateTimeImmutable(sprintf('%04d-%02d-%02d', $anio, $mes, $dia));
    }

    private static function digitoCorrecto(string $curp): bool
    {
        $suma = 0;

        // Los primeros 17 caracteres pesan de 18 a 2; el 18º es el resultado.
        for ($i = 0; $i < 17; $i++) {
            $posicion = mb_strpos(self::ALFABETO, mb_substr($curp, $i, 1));

            if ($posicion === false) {
                return false;
            }

            $suma += $posicion * (18 - $i);
        }

        return (string) ((10 - $suma % 10) % 10) === mb_substr($curp, 17, 1);
    }
}
