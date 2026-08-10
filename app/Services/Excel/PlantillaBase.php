<?php

declare(strict_types=1);

namespace App\Services\Excel;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Helpers compartidos para generar plantillas de carga masiva en Excel: hoja de
 * captura con encabezados pintados y desplegables validados, hoja de listas
 * oculta, instrucciones y guardado. Cada plantilla concreta (docentes, alumnos,
 * kárdex) arma sus hojas con estos ladrillos.
 */
abstract class PlantillaBase
{
    /** Filas de captura con validación bajo cada encabezado. */
    protected const FILAS = 500;

    /**
     * Crea la hoja oculta `_listas` (una columna por catálogo) y devuelve el
     * rango de cada una para las validaciones.
     *
     * @param  array<string, array<int, string>>  $catalogos  clave => [valores]
     * @return array<string, string> clave => rango Excel
     */
    protected function listas(Spreadsheet $libro, array $catalogos): array
    {
        $hoja = new Worksheet($libro, '_listas');
        $libro->addSheet($hoja);
        $hoja->setSheetState(Worksheet::SHEETSTATE_VERYHIDDEN);

        $rangos = [];
        $i = 0;
        foreach ($catalogos as $clave => $valores) {
            $col = $this->letra($i);
            $fila = 1;
            foreach ($valores as $valor) {
                $hoja->setCellValue("{$col}{$fila}", $valor);
                $fila++;
            }
            $ultima = max(1, count($valores));
            $rangos[$clave] = "_listas!\${$col}\$1:\${$col}\${$ultima}";
            $i++;
        }

        return $rangos;
    }

    /**
     * Hoja de captura con encabezados (col 1) validados y un ejemplo en el
     * comentario de cada encabezado. `$columnas` = [[titulo, rangoLista|null], …].
     *
     * @param  array<int, array{0: string, 1: string|null}>  $columnas
     * @param  array<int, mixed>  $ejemplo
     */
    protected function hoja(Spreadsheet $libro, string $nombre, array $columnas, array $ejemplo): void
    {
        $hoja = new Worksheet($libro, $nombre);
        $libro->addSheet($hoja);

        foreach ($columnas as $i => [$titulo, $rango]) {
            $letra = $this->letra($i);
            $hoja->setCellValue("{$letra}1", $titulo);
            $hoja->getColumnDimension($letra)->setWidth(max(14, mb_strlen($titulo) + 4));

            if (array_key_exists($i, $ejemplo)) {
                $comentario = $hoja->getComment("{$letra}1");
                $comentario->getText()->createTextRun('Ejemplo: '.$ejemplo[$i]);
                $comentario->setWidth('160pt')->setHeight('40pt');
            }

            if ($rango !== null) {
                $this->validarLista($hoja, $letra, $rango);
            }
        }

        $ultimaCol = $this->letra(count($columnas) - 1);
        $encabezado = $hoja->getStyle("A1:{$ultimaCol}1");
        $encabezado->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
        $encabezado->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF2F6FED');
        $encabezado->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $hoja->freezePane('A2');
    }

    private function validarLista(Worksheet $hoja, string $letra, string $rango): void
    {
        for ($fila = 2; $fila <= static::FILAS; $fila++) {
            $dv = $hoja->getCell("{$letra}{$fila}")->getDataValidation();
            $dv->setType(DataValidation::TYPE_LIST);
            $dv->setErrorStyle(DataValidation::STYLE_STOP);
            $dv->setAllowBlank(true);
            $dv->setShowDropDown(true);
            $dv->setShowErrorMessage(true);
            $dv->setErrorTitle('Valor no permitido');
            $dv->setError('Elige un valor de la lista desplegable.');
            $dv->setFormula1($rango);
        }
    }

    /** @param  array<int, string>  $lineas */
    protected function instrucciones(Spreadsheet $libro, array $lineas): void
    {
        $hoja = new Worksheet($libro, 'Instrucciones');
        $libro->addSheet($hoja);
        $hoja->getColumnDimension('A')->setWidth(110);
        $hoja->setCellValue('A1', 'Instrucciones de llenado');
        $hoja->getStyle('A1')->getFont()->setBold(true)->setSize(14);

        $fila = 3;
        foreach ($lineas as $linea) {
            $hoja->setCellValue("A{$fila}", '•  '.$linea);
            $hoja->getStyle("A{$fila}")->getAlignment()->setWrapText(true);
            $fila++;
        }
    }

    protected function primero(string $modelo, string $columna = 'nombre'): string
    {
        return (string) $modelo::query()->orderBy($columna)->value($columna);
    }

    protected function letra(int $indice): string
    {
        return Coordinate::stringFromColumnIndex($indice + 1);
    }

    protected function guardar(Spreadsheet $libro, string $prefijo = 'plantilla'): string
    {
        $ruta = tempnam(sys_get_temp_dir(), $prefijo).'.xlsx';
        (new Xlsx($libro))->save($ruta);

        return $ruta;
    }
}
