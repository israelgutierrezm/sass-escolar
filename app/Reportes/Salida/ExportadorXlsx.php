<?php

declare(strict_types=1);

namespace App\Reportes\Salida;

use App\Configuracion\Ajustes;
use App\Configuracion\CatalogoAjustes;
use App\Exceptions\AvisoParaElUsuario;
use App\Reportes\ColumnaReporte;
use App\Reportes\Exportacion;
use App\Reportes\TipoDato;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * El reporte en Excel.
 *
 * ── Tiene TOPE, y se avisa ANTES ─────────────────────────────────────────
 * PhpSpreadsheet arma el libro entero en memoria: no hay forma de escribir un
 * .xlsx en streaming. Así que el tope se comprueba antes de armar nada y el
 * mensaje dice la cifra real y la salida —«trae 32 400 filas; en Excel el tope
 * son 5 000; descárgalo en CSV o acota los filtros»— en vez de un
 * `Allowed memory size exhausted` a los tres minutos, que no le dice a nadie
 * qué hacer.
 *
 * ── El formato de celda es la mitad del valor de un Excel ────────────────
 * Una fecha que viaja como texto no se puede ordenar ni restar en la hoja, y un
 * importe como texto no se suma. Sin error, sin aviso: el archivo se abre y no
 * sirve para trabajar. Por eso cada columna se formatea según su `TipoDato`.
 *
 * ── Anchos fijos, no `setAutoSize` ───────────────────────────────────────
 * El autoajuste mide cada celda de cada columna al guardar: con miles de filas
 * es la parte más cara del archivo. El ancho lo sugiere la columna.
 */
class ExportadorXlsx
{
    public function __construct(private readonly Ajustes $ajustes) {}

    public function responder(Exportacion $exportacion): StreamedResponse
    {
        $tope = $this->ajustes->entero(CatalogoAjustes::TOPE_FILAS_XLSX);

        AvisoParaElUsuario::si(
            $exportacion->total > $tope,
            422,
            "Este reporte trae {$exportacion->total} filas y en Excel el tope son {$tope}: "
            .'descárgalo en CSV, que no tiene límite, o acota los filtros.',
        );

        return response()->streamDownload(
            function () use ($exportacion) {
                $libro = new Spreadsheet;
                $hoja = $libro->getActiveSheet();

                $this->encabezado($hoja, $exportacion->columnas);

                $renglon = 2;

                foreach ($exportacion->recorrer() as $fila) {
                    foreach ($exportacion->columnas as $i => $columna) {
                        $hoja->setCellValue(
                            [$i + 1, $renglon],
                            $this->celda($fila[$columna->clave] ?? null, $columna->tipo),
                        );
                    }

                    $renglon++;
                }

                $this->formatear($hoja, $exportacion->columnas, $renglon - 1);
                $hoja->setTitle(mb_substr($exportacion->reporte->titulo(), 0, 31));

                (new Xlsx($libro))->save('php://output');

                // Se libera: el libro entero sigue en memoria hasta aquí.
                $libro->disconnectWorksheets();

                ($exportacion->alTerminar)($renglon - 2, 'xlsx');
            },
            $exportacion->archivo('xlsx'),
            [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Cache-Control' => 'no-store, max-age=0',
            ],
        );
    }

    /** @param  array<int, ColumnaReporte>  $columnas */
    private function encabezado($hoja, array $columnas): void
    {
        foreach ($columnas as $i => $columna) {
            $hoja->setCellValue([$i + 1, 1], $columna->etiqueta);
            // El ancho lo sugiere la columna: `setAutoSize` mide cada celda al
            // guardar y es la parte más cara con miles de filas.
            $hoja->getColumnDimensionByColumn($i + 1)->setWidth($columna->ancho);
        }

        $ultima = Coordinate::stringFromColumnIndex(max(1, count($columnas)));
        $hoja->getStyle("A1:{$ultima}1")->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
        $hoja->getStyle("A1:{$ultima}1")->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setARGB('FF2F6FED');

        // La cabecera se congela: sin esto, al bajar por mil filas ya no se sabe
        // qué columna es cuál.
        $hoja->freezePane('A2');
    }

    /**
     * El valor con el TIPO que Excel entiende, no como texto.
     *
     * Una fecha va como número de serie de Excel; sin eso, «ordenar por fecha de
     * ingreso» dentro de la hoja ordena alfabéticamente y nadie entiende por qué.
     */
    private function celda(mixed $valor, TipoDato $tipo): mixed
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        return match ($tipo) {
            TipoDato::Fecha, TipoDato::FechaHora => $valor instanceof \DateTimeInterface
                ? Date::PHPToExcel($valor)
                : (strtotime((string) $valor) ? Date::PHPToExcel(strtotime((string) $valor)) : $valor),
            TipoDato::Entero => (int) $valor,
            TipoDato::Decimal, TipoDato::Dinero, TipoDato::Porcentaje => (float) $valor,
            TipoDato::Booleano => (bool) $valor,
            default => TextoDeCelda::neutralizado((string) $valor),
        };
    }

    /** @param  array<int, ColumnaReporte>  $columnas */
    private function formatear($hoja, array $columnas, int $ultimaFila): void
    {
        if ($ultimaFila < 2) {
            return;
        }

        foreach ($columnas as $i => $columna) {
            $letra = Coordinate::stringFromColumnIndex($i + 1);
            $rango = "{$letra}2:{$letra}{$ultimaFila}";

            $formato = match ($columna->tipo) {
                TipoDato::Fecha => NumberFormat::FORMAT_DATE_DDMMYYYY,
                TipoDato::FechaHora => NumberFormat::FORMAT_DATE_DATETIME,
                /*
                 * El formato del dinero va ESCRITO, no por constante.
                 *
                 * Aquí decía `NumberFormat::FORMAT_CURRENCY_USD_SIMPLE`, que **no
                 * existe** en esta versión de PhpSpreadsheet: el botón «Excel»
                 * devolvía una página de error en TODO reporte con una columna
                 * de dinero y al menos una fila —o sea en casi todo finanzas—,
                 * mientras el CSV del mismo reporte salía bien.
                 *
                 * Ninguna suite lo veía: la única que ejercitaba el XLSX
                 * exportaba «Alumnos inscritos», cuya única columna numérica es
                 * un entero, así que esta rama nunca se pisaba.
                 *
                 * Y las constantes que SÍ existen son de dólares, libras, euros
                 * y yenes. Esto factura en pesos, así que el literal es la
                 * respuesta correcta y no un atajo — con la coma de miles y dos
                 * decimales, que es como se leen aquí.
                 */
                TipoDato::Dinero => '"$"#,##0.00',
                TipoDato::Porcentaje => NumberFormat::FORMAT_PERCENTAGE_00,
                TipoDato::Decimal => NumberFormat::FORMAT_NUMBER_00,
                TipoDato::Entero => NumberFormat::FORMAT_NUMBER,
                default => null,
            };

            if ($formato !== null) {
                $hoja->getStyle($rango)->getNumberFormat()->setFormatCode($formato);
            }
        }
    }
}
