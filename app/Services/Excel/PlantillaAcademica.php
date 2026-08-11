<?php

declare(strict_types=1);

namespace App\Services\Excel;

use App\Models\Academico\Area;
use App\Models\Academico\AutorizacionReconocimiento;
use App\Models\Academico\ClasificacionAsignatura;
use App\Models\Academico\NivelEstudio;
use App\Models\Academico\TipoAsignatura;
use App\Models\Academico\TipoCampus;
use App\Models\Academico\TipoPeriodo;
use App\Models\Landlord\EntidadFederativa;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Genera las plantillas de carga masiva en Excel, con listas desplegables
 * tomadas de los catálogos de LA escuela (no fijas) y una fila de ejemplo.
 *
 * Las cabeceras y encabezados de columna se pintan; los desplegables validan
 * que no se escriba un valor fuera del catálogo. La primera columna de cada
 * hoja de referencia (clave) es la que enlaza con las otras hojas.
 */
class PlantillaAcademica
{
    /** Filas de captura con validación bajo cada encabezado. */
    private const FILAS = 300;

    /**
     * Plantilla COMPLETA: campus, carreras, planes y asignaturas. La institución
     * NO va aquí; se carga aparte, a mano, porque hay una sola por escuela.
     *
     * @return string ruta del .xlsx temporal
     */
    public function completa(): string
    {
        $libro = new Spreadsheet;
        $libro->removeSheetByIndex(0);

        $rangos = $this->hojaListas($libro);

        $this->instrucciones($libro, [
            'Llena una fila por registro. La primera fila (gris) es solo un ejemplo: bórrala o reemplázala.',
            'Las columnas con lista desplegable solo aceptan valores del catálogo de tu escuela.',
            'Los encabezados con * son obligatorios.',
            'Institución (clave) en «Campus» debe coincidir con una Clave de la hoja «Institución».',
            'Carrera (clave) en «Planes» debe coincidir con una Clave de la hoja «Carreras».',
            'Plan (clave) en «Asignaturas» debe coincidir con una Clave de la hoja «Planes».',
            'Para el certificado electrónico, el campus necesita entidad federativa y estar ligado a una institución.',
        ]);

        // La institución (nombre oficial): una o varias por escuela. El campus se
        // liga a ella por clave.
        $this->hoja($libro, 'Institución', [
            ['Clave *', null], ['Nombre oficial *', null], ['Nombre a mostrar', null], ['Siglas', null],
        ], ['IPES-DEMO', 'Instituto Demo de Educación Superior', 'Instituto Demo', 'IDES']);

        $this->hoja($libro, 'Campus', [
            ['Clave *', null], ['Nombre *', null], ['Institución (clave)', null],
            ['Entidad federativa', $rangos['entidades']], ['Tipo de campus', $rangos['tiposCampus']],
        ], ['CEN', 'Campus Central', 'IPES-DEMO', $this->primero(EntidadFederativa::class), $this->primero(TipoCampus::class)]);

        $this->hoja($libro, 'Carreras', [
            ['Identificador *', null], ['Clave *', null], ['Nombre *', null], ['Nivel *', $rangos['niveles']],
        ], ['ISC', 'ISC-2024', 'Ingeniería en Sistemas', $this->primero(NivelEstudio::class)]);

        $this->hoja($libro, 'Planes', [
            ['Carrera (clave) *', null], ['Clave *', null], ['Nombre *', null],
            ['Tipo de periodo *', $rangos['tiposPeriodo']], ['Total de periodos *', null],
            ['Número de asignaturas para completar la carrera', null], ['Créditos para completar la carrera', null],
            ['Calif. mínima *', null], ['Calif. máxima *', null], ['Calif. mínima aprobatoria *', null],
            ['Tipo de autorización *', $rangos['autorizaciones']], ['RVOE', null], ['Fecha de RVOE (AAAA-MM-DD)', null],
        ], ['ISC-2024', 'PLAN-ISC-24', 'Plan ISC 2024', $this->primero(TipoPeriodo::class), 9, 50, 300, 0, 100, 70, $this->primero(AutorizacionReconocimiento::class), 'RVOE-12345', '2024-08-15']);

        $this->hoja($libro, 'Asignaturas', [
            ['Plan (clave) *', null], ['Identificador *', null], ['Clave *', null], ['Nombre *', null],
            ['Créditos *', null], ['Tipo de asignatura *', $rangos['tiposAsignatura']], ['Periodo', null],
            ['Horas teoría', null], ['Horas práctica', null], ['Horas acompañamiento', null], ['Horas independientes', null],
            ['Área', $rangos['areas']], ['Clasificación', $rangos['clasificaciones']],
        ], ['PLAN-ISC-24', 'MAT-101', 'MAT-101', 'Cálculo I', 8, $this->primero(TipoAsignatura::class), 1, 3, 2, 1, 2, $this->primero(Area::class), $this->primero(ClasificacionAsignatura::class)]);

        $libro->setActiveSheetIndexByName('Instrucciones');

        return $this->guardar($libro);
    }

    /**
     * Plantilla de ASIGNATURAS para un plan (desde la malla): sin la columna de
     * plan, porque el plan es el contexto.
     *
     * @return string ruta del .xlsx temporal
     */
    public function asignaturasDePlan(string $nombrePlan): string
    {
        $libro = new Spreadsheet;
        $libro->removeSheetByIndex(0);

        $rangos = $this->hojaListas($libro);

        $this->instrucciones($libro, [
            "Asignaturas del plan: {$nombrePlan}.",
            'Una fila por asignatura. La primera fila (gris) es un ejemplo.',
            'Las columnas con lista desplegable solo aceptan valores del catálogo.',
            'Los encabezados con * son obligatorios.',
        ]);

        $this->hoja($libro, 'Asignaturas', [
            ['Identificador *', null], ['Clave *', null], ['Nombre *', null],
            ['Créditos *', null], ['Tipo de asignatura *', $rangos['tiposAsignatura']], ['Periodo', null],
            ['Horas teoría', null], ['Horas práctica', null], ['Horas acompañamiento', null], ['Horas independientes', null],
            ['Área', $rangos['areas']], ['Clasificación', $rangos['clasificaciones']],
        ], ['MAT-101', 'MAT-101', 'Cálculo I', 8, $this->primero(TipoAsignatura::class), 1, 3, 2, 1, 2, $this->primero(Area::class), $this->primero(ClasificacionAsignatura::class)]);

        $libro->setActiveSheetIndexByName('Instrucciones');

        return $this->guardar($libro);
    }

    /**
     * Crea la hoja oculta de listas (una columna por catálogo) y devuelve el
     * rango de cada una para las validaciones.
     *
     * @return array<string, string>
     */
    private function hojaListas(Spreadsheet $libro): array
    {
        $hoja = new Worksheet($libro, '_listas');
        $libro->addSheet($hoja);
        $hoja->setSheetState(Worksheet::SHEETSTATE_VERYHIDDEN);

        $catalogos = [
            'A' => ['tiposCampus', TipoCampus::query()->orderBy('nombre')->pluck('nombre')->all()],
            'B' => ['niveles', NivelEstudio::query()->activos()->orderBy('nombre')->pluck('nombre')->all()],
            'C' => ['tiposPeriodo', TipoPeriodo::query()->activos()->orderBy('nombre')->pluck('nombre')->all()],
            'D' => ['tiposAsignatura', TipoAsignatura::query()->orderBy('nombre')->pluck('nombre')->all()],
            'E' => ['areas', Area::query()->orderBy('nombre')->pluck('nombre')->all()],
            'F' => ['clasificaciones', ClasificacionAsignatura::query()->orderBy('nombre')->pluck('nombre')->all()],
            'G' => ['siNo', ['Sí', 'No']],
            'H' => ['ubicacion', ['Obligatoria', 'Optativa', 'Tronco común']],
            'I' => ['autorizaciones', AutorizacionReconocimiento::query()->orderBy('nombre')->pluck('nombre')->all()],
            'J' => ['entidades', EntidadFederativa::query()->orderBy('nombre')->pluck('nombre')->all()],
        ];

        $rangos = [];
        foreach ($catalogos as $col => [$clave, $valores]) {
            $fila = 1;
            foreach ($valores as $valor) {
                $hoja->setCellValue("{$col}{$fila}", $valor);
                $fila++;
            }
            $ultima = max(1, count($valores));
            $rangos[$clave] = "_listas!\${$col}\$1:\${$col}\${$ultima}";
        }

        return $rangos;
    }

    /**
     * Crea una hoja de captura con encabezados (col 1) validados y una fila de
     * ejemplo. `$columnas` = [[titulo, rangoLista|null], ...].
     *
     * @param  array<int, array{0: string, 1: string|null}>  $columnas
     * @param  array<int, mixed>  $ejemplo
     */
    private function hoja(Spreadsheet $libro, string $nombre, array $columnas, array $ejemplo): void
    {
        $hoja = new Worksheet($libro, $nombre);
        $libro->addSheet($hoja);

        foreach ($columnas as $i => [$titulo, $rango]) {
            $letra = $this->letra($i);
            $hoja->setCellValue("{$letra}1", $titulo);
            $hoja->getColumnDimension($letra)->setWidth(max(14, mb_strlen($titulo) + 4));

            // El ejemplo va como COMENTARIO del encabezado (no como fila de
            // datos) para que no se importe por error. Los datos empiezan en la
            // fila 2, vacía.
            if (array_key_exists($i, $ejemplo)) {
                $comentario = $hoja->getComment("{$letra}1");
                $comentario->getText()->createTextRun('Ejemplo: '.$ejemplo[$i]);
                $comentario->setWidth('160pt')->setHeight('40pt');
            }

            if ($rango !== null) {
                $this->validarLista($hoja, $letra, $rango);
            }
        }

        // Estilo del encabezado.
        $ultimaCol = $this->letra(count($columnas) - 1);
        $encabezado = $hoja->getStyle("A1:{$ultimaCol}1");
        $encabezado->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
        $encabezado->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF2F6FED');
        $encabezado->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $hoja->freezePane('A2');
    }

    /** Aplica validación de lista (desplegable) a la columna, filas 2..FILAS. */
    private function validarLista(Worksheet $hoja, string $letra, string $rango): void
    {
        for ($fila = 2; $fila <= self::FILAS; $fila++) {
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

    /** Hoja de instrucciones con viñetas. */
    private function instrucciones(Spreadsheet $libro, array $lineas): void
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

    private function primero(string $modelo): string
    {
        return (string) $modelo::query()->orderBy('nombre')->value('nombre');
    }

    private function letra(int $indice): string
    {
        return Coordinate::stringFromColumnIndex($indice + 1);
    }

    private function guardar(Spreadsheet $libro): string
    {
        $ruta = tempnam(sys_get_temp_dir(), 'plan_acad').'.xlsx';
        (new Xlsx($libro))->save($ruta);

        return $ruta;
    }
}
