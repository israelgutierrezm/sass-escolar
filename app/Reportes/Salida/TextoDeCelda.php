<?php

declare(strict_types=1);

namespace App\Reportes\Salida;

/**
 * Texto que no se convierte en fórmula al abrir el archivo.
 *
 * ── El problema ──────────────────────────────────────────────────────────
 * Excel y LibreOffice interpretan como FÓRMULA cualquier celda que empiece por
 * `=`, `+`, `-`, `@`, tabulador o retorno de carro. Y un reporte escolar está
 * lleno de texto que escribió alguien de fuera: el nombre de un aspirante que
 * llegó por el formulario público, el nombre de una empresa capturado por
 * teléfono, el motivo de una baja.
 *
 * Una celda con `=HYPERLINK("http://…"&A2,"Haz clic")` en el archivo que control
 * escolar le manda por correo a la SEP es una fuga de datos que se dispara sola
 * al abrirlo, y no hay nada en el archivo que la delate: parece texto.
 *
 * ── Por qué un apóstrofo y no quitar el carácter ─────────────────────────
 * Porque el dato tiene que seguir siendo el dato. Una cuenta contable que de
 * verdad empieza con `-` o un identificador que empieza con `+` no se pueden
 * mutilar: el apóstrofo inicial es la marca estándar de «esto es texto», Excel
 * la consume al abrir y el valor se lee igual.
 */
final class TextoDeCelda
{
    /** Los que Excel toma como principio de fórmula. */
    private const PELIGROSOS = ['=', '+', '-', '@', "\t", "\r"];

    public static function neutralizado(string $texto): string
    {
        if ($texto === '') {
            return $texto;
        }

        return in_array($texto[0], self::PELIGROSOS, true) ? "'".$texto : $texto;
    }
}
