<?php

declare(strict_types=1);

namespace App\Services\Excel;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Una tabla —encabezados y filas— descargada como .xlsx.
 *
 * ── Por qué existe ────────────────────────────────────────────────────────
 * Este método estaba DUPLICADO carácter por carácter en
 * `LoteCertificacionController` y `LoteTitulacionController`. Dos copias de un
 * generador de archivos es como se llega a que una arregle el ancho de columna
 * y la otra no, y nadie sepa por qué el mismo botón produce dos formatos. Sube
 * aquí antes de que aparezca la tercera copia —el módulo de reportes—.
 *
 * ── La fuga del temporal, que sí era real ─────────────────────────────────
 * Las dos copias hacían `tempnam(sys_get_temp_dir(), 'xls').'.xlsx'`. `tempnam`
 * CREA el archivo y devuelve su ruta; al pegarle la extensión se guarda en OTRA
 * ruta, así que el `.tmp` original quedaba huérfano —y `deleteFileAfterSend()`
 * sólo se lleva el `.xlsx`—. Cada descarga dejaba un archivo vacío en el
 * temporal del sistema, para siempre. Aquí el `.tmp` se borra en cuanto se sabe
 * el nombre definitivo.
 *
 * Se queda en disco y no en `php://output` a propósito: el que llama devuelve
 * una respuesta de descarga y el proyecto ya lo hace así en los dos sitios que
 * lo usaban. Un exportador en streaming es otra cosa —para volúmenes grandes— y
 * llegará con quien lo necesite.
 */
class Exportador
{
    /**
     * @param  array<int, string>  $encabezados
     * @param  array<int, array<int, mixed>>  $filas
     * @param  string  $titulo  nombre de la hoja; Excel lo topa en 31 caracteres
     * @param  string  $archivo  nombre con el que se descarga
     */
    public function descargar(string $titulo, array $encabezados, array $filas, string $archivo): BinaryFileResponse
    {
        $libro = new Spreadsheet;
        $hoja = $libro->getActiveSheet();

        $hoja->fromArray($encabezados, null, 'A1');

        $ultima = Coordinate::stringFromColumnIndex(max(1, count($encabezados)));
        $hoja->getStyle("A1:{$ultima}1")->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
        $hoja->getStyle("A1:{$ultima}1")->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setARGB('FF2F6FED');

        $hoja->fromArray($filas, null, 'A2');

        foreach (range(1, max(1, count($encabezados))) as $i) {
            $hoja->getColumnDimensionByColumn($i)->setAutoSize(true);
        }

        $hoja->setTitle(mb_substr($titulo, 0, 31));

        $destino = $this->rutaTemporal();
        (new Xlsx($libro))->save($destino);

        return response()->download($destino, $archivo)->deleteFileAfterSend(true);
    }

    /**
     * Una ruta temporal libre con extensión .xlsx, sin dejar basura detrás.
     *
     * `tempnam` es lo que garantiza que el nombre no choque con otra petición
     * simultánea, así que se usa igual; lo que se corrige es que el archivo que
     * crea se borra en vez de quedarse ahí cuando el escritor guarda en la ruta
     * con extensión.
     */
    private function rutaTemporal(): string
    {
        $reserva = tempnam(sys_get_temp_dir(), 'acadion-xls');
        $destino = $reserva.'.xlsx';

        // El escritor guarda en `$destino`; `$reserva` ya no lo usa nadie.
        @unlink($reserva);

        return $destino;
    }
}
