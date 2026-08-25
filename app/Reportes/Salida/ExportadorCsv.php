<?php

declare(strict_types=1);

namespace App\Reportes\Salida;

use App\Reportes\ColumnaReporte;
use App\Reportes\Exportacion;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * El reporte en CSV. Es el que aguanta el volumen.
 *
 * ── Sin PhpSpreadsheet, a propósito ──────────────────────────────────────
 * Su escritor de CSV también exige el `Spreadsheet` completo en memoria, o sea
 * que tendría el mismo techo que el XLSX y ninguna de sus ventajas. Con
 * `fputcsv` contra `php://output` la memoria es constante: da igual que sean mil
 * filas o cien mil.
 *
 * ── BOM y punto y coma: los dos hacen falta ──────────────────────────────
 * Sin el BOM, «Gutiérrez» sale roto al abrirlo en Excel. Con coma en vez de
 * punto y coma, un Excel en español mete la fila entera en una sola columna.
 * Ninguna de las dos cosas da error: producen un archivo que se abre y está mal,
 * que es la peor forma de fallar.
 */
class ExportadorCsv
{
    public function responder(Exportacion $exportacion): StreamedResponse
    {
        return response()->streamDownload(
            function () use ($exportacion) {
                $salida = fopen('php://output', 'w');

                // BOM UTF-8: es lo que le dice a Excel cómo leer los acentos.
                fwrite($salida, chr(0xEF).chr(0xBB).chr(0xBF));

                fputcsv($salida, array_map(fn (ColumnaReporte $c) => $c->etiqueta, $exportacion->columnas), ';');

                $filas = 0;

                foreach ($exportacion->recorrer() as $fila) {
                    fputcsv($salida, array_map(
                        fn (ColumnaReporte $c) => $this->celda($fila[$c->clave] ?? null),
                        $exportacion->columnas,
                    ), ';');

                    $filas++;

                    // Se vacía el búfer cada tanto: sin esto, PHP acumula la
                    // salida y la memoria vuelve a crecer con el archivo.
                    if ($filas % 500 === 0) {
                        flush();
                    }
                }

                fclose($salida);

                ($exportacion->alTerminar)($filas, 'csv');
            },
            $exportacion->archivo('csv'),
            [
                'Content-Type' => 'text/csv; charset=UTF-8',
                // Sin caché: un reporte se vuelve a pedir porque los datos
                // cambiaron, y servir el de hace una hora es servir una mentira.
                'Cache-Control' => 'no-store, max-age=0',
            ],
        );
    }

    /** Los valores viajan como texto plano; las fechas en ISO, que Excel entiende. */
    private function celda(mixed $valor): string
    {
        return match (true) {
            $valor === null => '',
            is_bool($valor) => $valor ? 'Sí' : 'No',
            $valor instanceof \DateTimeInterface => $valor->format('Y-m-d'),
            default => (string) $valor,
        };
    }
}
