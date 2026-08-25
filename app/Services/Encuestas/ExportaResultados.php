<?php

declare(strict_types=1);

namespace App\Services\Encuestas;

use App\Enums\TipoPregunta;
use App\Models\Encuestas\AplicacionEncuesta;
use App\Models\Encuestas\Pregunta;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Los resultados en un archivo que se pueda repartir.
 *
 * ── Por qué hace falta si ya está la pantalla ──────────────────────────────
 * Un consejo académico se reúne con papeles, y quien tiene que hablar con un
 * docente sobre su evaluación necesita llevarle algo. Además, la pantalla
 * enseña UNA encuesta: cruzar estos números con lo que la escuela ya sepa
 * —bajas del grupo, antigüedad del docente— sólo se puede hacer fuera.
 *
 * ── El umbral de anonimato viaja con los datos ─────────────────────────────
 * Lo que la pantalla oculta, el archivo también. Sería absurdo proteger el
 * anonimato en el navegador y regalarlo en un Excel que va a acabar por correo:
 * el archivo se reenvía más fácil que una pantalla.
 */
class ExportaResultados
{
    public function __construct(private readonly ResultadosDeEncuesta $resultados) {}

    /** Devuelve la ruta del archivo generado. */
    public function generar(AplicacionEncuesta $aplicacion): string
    {
        $aplicacion->loadMissing('encuesta.preguntas.opciones');

        $libro = new Spreadsheet;
        $libro->removeSheetByIndex(0);

        $this->hojaResumen($libro, $aplicacion);

        if ($aplicacion->esDocente()) {
            $this->hojaDocentes($libro, $aplicacion);
        }

        $this->hojaAbiertas($libro, $aplicacion);

        $libro->setActiveSheetIndex(0);

        $ruta = tempnam(sys_get_temp_dir(), 'encuesta').'.xlsx';
        (new Xlsx($libro))->save($ruta);

        return $ruta;
    }

    /** Pregunta por pregunta, con el dato que corresponde a cada tipo. */
    private function hojaResumen(Spreadsheet $libro, AplicacionEncuesta $aplicacion): void
    {
        $hoja = new Worksheet($libro, 'Resumen');
        $libro->addSheet($hoja);

        $datos = $this->resultados->de($aplicacion);

        $hoja->setCellValue('A1', $aplicacion->titulo);
        $hoja->getStyle('A1')->getFont()->setBold(true)->setSize(14);

        $hoja->setCellValue('A2', 'Respuestas recibidas');
        $hoja->setCellValue('B2', $datos['respuestas']);
        $hoja->setCellValue('A3', 'Anónima');
        $hoja->setCellValue('B3', $aplicacion->anonima ? 'Sí' : 'No');

        $fila = 5;
        $this->encabezado($hoja, $fila, ['Pregunta', 'Tipo', 'Respuestas', 'Resultado', 'Detalle']);
        $fila++;

        foreach ($datos['preguntas'] as $pregunta) {
            $hoja->setCellValue("A{$fila}", $pregunta['texto']);
            $hoja->setCellValue("B{$fila}", $pregunta['tipo_etiqueta']);

            if (isset($pregunta['promedio'])) {
                $hoja->setCellValue("C{$fila}", $pregunta['contestadas']);
                $hoja->setCellValue("D{$fila}", $pregunta['promedio']);
                $hoja->setCellValue("E{$fila}", sprintf(
                    'de %s · entre %s y %s',
                    $pregunta['escala_maxima'],
                    $pregunta['minimo'] ?? '—',
                    $pregunta['maximo'] ?? '—',
                ));
                $fila++;

                // El reparto debajo: un promedio de 3.5 puede ser «todos
                // regulares» o «la mitad encantada y la mitad furiosa», y en una
                // sola celda esa diferencia no cabe.
                foreach ($pregunta['distribucion'] as $punto) {
                    $hoja->setCellValue("D{$fila}", $punto['valor']);
                    $hoja->setCellValue("E{$fila}", $punto['total'].' respuestas');
                    $fila++;
                }

                continue;
            }

            if (isset($pregunta['opciones'])) {
                $fila++;

                foreach ($pregunta['opciones'] as $opcion) {
                    $hoja->setCellValue("D{$fila}", $opcion['texto']);
                    $hoja->setCellValue("E{$fila}", "{$opcion['total']} ({$opcion['porcentaje']}%)");
                    $fila++;
                }

                continue;
            }

            // Las abiertas tienen su propia hoja: aquí sólo cuántas hay.
            $hoja->setCellValue("C{$fila}", count($pregunta['textos'] ?? []));
            $hoja->setCellValue("E{$fila}", 'Ver la hoja «Respuestas abiertas»');
            $fila++;
        }

        $this->anchos($hoja, ['A' => 55, 'B' => 18, 'C' => 12, 'D' => 16, 'E' => 40]);
    }

    /** El tablero por docente, en el mismo orden que la pantalla. */
    private function hojaDocentes(Spreadsheet $libro, AplicacionEncuesta $aplicacion): void
    {
        $hoja = new Worksheet($libro, 'Por docente');
        $libro->addSheet($hoja);

        $fila = 1;
        $this->encabezado($hoja, $fila, ['Docente', 'Materia', 'Grupo', 'Papel', 'Respuestas', 'Esperadas', 'Participación', 'Promedio']);
        $fila++;

        foreach ($this->resultados->porSujeto($aplicacion) as $sujeto) {
            $hoja->setCellValue("A{$fila}", $sujeto['docente']);
            $hoja->setCellValue("B{$fila}", $sujeto['materia'] ?? '');
            $hoja->setCellValue("C{$fila}", $sujeto['grupo'] ?? '');
            $hoja->setCellValue("D{$fila}", $sujeto['papel'] ?? '');
            $hoja->setCellValue("E{$fila}", $sujeto['respuestas']);
            $hoja->setCellValue("F{$fila}", $sujeto['esperadas']);
            $hoja->setCellValue("G{$fila}", $sujeto['esperadas'] === 0
                ? ''
                : round($sujeto['respuestas'] * 100 / $sujeto['esperadas']).'%');

            // Se dice POR QUÉ está vacío. Una celda en blanco en un Excel que
            // circula se interpreta como un cero, y un cero aquí sería acusar a
            // alguien de algo que no dijeron sus alumnos.
            $hoja->setCellValue("H{$fila}", $sujeto['promedio'] ?? 'Sin datos suficientes');

            $fila++;
        }

        $this->anchos($hoja, ['A' => 32, 'B' => 32, 'C' => 12, 'D' => 12, 'E' => 12, 'F' => 12, 'G' => 14, 'H' => 22]);
    }

    /**
     * Las respuestas escritas, cada una en su renglón.
     *
     * En orden aleatorio, como en la pantalla: el orden de captura, cruzado con
     * la lista de quién participó, acabaría delatando quién dijo qué.
     */
    private function hojaAbiertas(Spreadsheet $libro, AplicacionEncuesta $aplicacion): void
    {
        $abiertas = $aplicacion->encuesta->preguntas
            ->filter(fn (Pregunta $p) => $p->tipo === TipoPregunta::Abierta);

        if ($abiertas->isEmpty()) {
            return;
        }

        $hoja = new Worksheet($libro, 'Respuestas abiertas');
        $libro->addSheet($hoja);

        $fila = 1;
        $this->encabezado($hoja, $fila, ['Pregunta', 'Respuesta']);
        $fila++;

        $datos = collect($this->resultados->de($aplicacion)['preguntas'])->keyBy('id');

        foreach ($abiertas as $pregunta) {
            foreach ($datos[$pregunta->id]['textos'] ?? [] as $texto) {
                $hoja->setCellValue("A{$fila}", $pregunta->texto);
                $hoja->setCellValue("B{$fila}", $texto);
                $hoja->getStyle("B{$fila}")->getAlignment()->setWrapText(true);
                $fila++;
            }
        }

        $this->anchos($hoja, ['A' => 45, 'B' => 90]);
    }

    /** @param  array<int, string>  $titulos */
    private function encabezado(Worksheet $hoja, int $fila, array $titulos): void
    {
        foreach ($titulos as $i => $titulo) {
            // Coordinate y no chr(ord('A') + $i): pasada la Z eso produce '[',
            // y una encuesta de más de 26 preguntas generaba un archivo corrupto.
            $columna = Coordinate::stringFromColumnIndex($i + 1);
            $hoja->setCellValue("{$columna}{$fila}", $titulo);
        }

        $ultima = Coordinate::stringFromColumnIndex(max(1, count($titulos)));
        $rango = "A{$fila}:{$ultima}{$fila}";

        $hoja->getStyle($rango)->getFont()->setBold(true);
        $hoja->getStyle($rango)->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB('EEF2F7');
        $hoja->getStyle($rango)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
    }

    /** @param  array<string, int>  $anchos */
    private function anchos(Worksheet $hoja, array $anchos): void
    {
        foreach ($anchos as $columna => $ancho) {
            $hoja->getColumnDimension($columna)->setWidth($ancho);
        }
    }
}
