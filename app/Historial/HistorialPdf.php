<?php

declare(strict_types=1);

namespace App\Historial;

use App\Documentos\DocumentoPdf;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\View;

/**
 * El historial académico armado como PDF.
 *
 * Traduce lo que el diseño de la escuela declara —membrete, folio, marca de
 * agua, papel— a lo que el motor necesita. El cuerpo lo dibuja la vista
 * `impresion.historial-pdf`; lo que se repite en cada hoja se arma AQUÍ, porque
 * eso no puede vivir dentro del cuerpo del documento.
 */
class HistorialPdf
{
    public function __construct(private readonly DocumentoPdf $pdf) {}

    /**
     * @param  array<string, mixed>  $armado  lo que devuelve HistorialImprimible
     */
    public function responder(array $armado, string $archivo): Response
    {
        $bytes = $this->generar($armado);

        return new Response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            /*
             * `inline` y no `attachment`: quien imprime un historial casi
             * siempre quiere MIRARLO antes de mandarlo a la impresora, y el
             * visor del navegador ya trae el botón de guardar. Con `attachment`
             * se descarga a ciegas y hay que abrirlo aparte.
             */
            'Content-Disposition' => 'inline; filename="'.$archivo.'"',
        ]);
    }

    /** @param  array<string, mixed>  $armado */
    public function generar(array $armado): string
    {
        $diseno = $armado['diseno'];
        $institucion = $armado['institucion'] ?? null;

        // La firma y el sello se INCRUSTAN. Por URL no llegarían: la ruta que
        // las sirve exige sesión y mpdf las pide con su propio cliente, sin la
        // cookie —el documento saldría sin firma y sin ningún error—.
        $firma = $this->pdf->imagenIncrustada($diseno->firma_imagen);
        $sello = $this->pdf->imagenIncrustada($diseno->sello_imagen);
        $logo = $diseno->muestra_logo ? $this->pdf->imagenIncrustada($institucion?->logo_url) : null;

        $html = View::make('impresion.historial-pdf', $armado + [
            'firma' => $firma,
            'sello' => $sello,
        ])->render();

        return $this->pdf->generar($html, [
            'titulo' => $diseno->titulo,
            'papel' => $diseno->tamano_papel,
            'orientacion' => $diseno->orientacion,
            'membrete' => $this->membrete($armado, $logo),
            'pie' => $this->pie($armado),
            'marca_agua' => $armado['marca_agua'] ?? null,
            // Con logo hace falta más margen arriba, o el membrete se encima
            // con la primera tabla.
            'margen_superior' => $logo !== null ? 40 : 32,
        ]);
    }

    /**
     * El membrete, que mpdf repite en CADA hoja.
     *
     * Es lo que arregla el defecto que el cliente llamó «no sirve»: antes salía
     * sólo en la primera y las hojas 2 y 3 no decían de quién eran ni de qué
     * escuela. Va en una TABLA y no en flex, porque mpdf no entiende flex.
     *
     * @param  array<string, mixed>  $armado
     */
    private function membrete(array $armado, ?string $logo): string
    {
        $diseno = $armado['diseno'];
        $institucion = $armado['institucion'] ?? null;

        $escuela = $diseno->muestra_nombre_escuela && $institucion !== null
            ? e($institucion->nombre_mostrar ?: $institucion->nombre)
            : '';

        $celdaLogo = $logo !== null
            ? '<td width="12%" style="vertical-align:middle"><img src="'.$logo.'" style="height:34pt"></td>'
            : '';

        // El contrapeso: una celda vacía del mismo ancho al otro lado, para que
        // el texto quede centrado en la HOJA y no en el hueco que deja el logo.
        $contrapeso = $logo !== null ? '<td width="12%"></td>' : '';

        $subtitulo = $diseno->subtitulo
            ? '<div style="font-size:8pt;color:#444">'.e($diseno->subtitulo).'</div>'
            : '';

        return '<table width="100%" style="border-bottom:0.6pt solid #333;padding-bottom:3pt">
            <tr>
                '.$celdaLogo.'
                <td style="text-align:center">
                    '.($escuela !== '' ? '<div style="font-size:10pt;font-weight:bold;text-transform:uppercase">'.$escuela.'</div>' : '').'
                    <div style="font-size:12pt;font-weight:bold">'.e($diseno->titulo).'</div>
                    '.$subtitulo.'
                </td>
                '.$contrapeso.'
            </tr>
        </table>';
    }

    /**
     * El pie: el folio de página y de quién es el documento.
     *
     * `{PAGENO}` y `{nbpg}` los sustituye mpdf al cerrar el documento, cuando ya
     * sabe cuántas hojas salieron. Es justo lo que el navegador no puede hacer
     * —ignora las cajas de margen de `@page` y `counter(page)`—, y por eso un
     * historial de tres hojas salía sin numerar.
     *
     * El nombre del alumno también va aquí: una hoja suelta que se separa del
     * juego tiene que poder devolverse a su expediente.
     *
     * @param  array<string, mixed>  $armado
     */
    private function pie(array $armado): string
    {
        $quien = '';

        foreach ($armado['datos'] ?? [] as $dato) {
            if (in_array(mb_strtolower($dato['etiqueta']), ['nombre', 'matrícula', 'matricula'], true)) {
                $quien .= ($quien === '' ? '' : ' · ').e($dato['valor']);
            }
        }

        return '<table width="100%" style="border-top:0.4pt solid #999;padding-top:2pt;font-size:7.5pt;color:#555">
            <tr>
                <td>'.$quien.'</td>
                <td style="text-align:right">Hoja {PAGENO} de {nbpg}</td>
            </tr>
        </table>';
    }
}
