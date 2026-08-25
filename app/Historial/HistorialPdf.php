<?php

declare(strict_types=1);

namespace App\Historial;

use App\Documentos\DocumentoPdf;
use App\Models\Identidad\Tema;
use App\Models\Identidad\TemaToken;
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

        /*
         * Las firmas y el sello se INCRUSTAN. Por URL no llegarían: la ruta que
         * las sirve exige sesión y mpdf las pide con su propio cliente, sin la
         * cookie —el documento saldría sin firmas y sin ningún error—.
         *
         * Los firmantes se resuelven aquí y no en la vista, para que la vista
         * reciba datos planos y no tenga que consultar la base mientras dibuja.
         *
         * Un diseño SIN GUARDAR —el de la vista previa cuando la escuela nunca
         * configuró nada— no necesita defensa: Eloquent devuelve una colección
         * vacía para una relación de un modelo que no existe. Se comprobó, y por
         * eso NO hay un `if ($diseno->exists)` aquí: sería código que aparenta
         * proteger algo que ya está resuelto.
         */
        $firmantes = $diseno->firmantes
            ->map(fn ($f) => [
                'nombre' => $f->nombre,
                'cargo' => $f->cargo,
                'firma' => $this->pdf->imagenIncrustada($f->firma_imagen),
            ])
            ->values()
            ->all();

        $sello = $this->pdf->imagenIncrustada($diseno->sello_imagen);
        $logo = $diseno->muestra_logo ? $this->pdf->imagenIncrustada($institucion?->logo_url) : null;

        $acento = $diseno->usa_color_acento ? $this->acentoDeLaEscuela() : null;

        $html = View::make('impresion.historial-pdf', $armado + [
            'firmantes' => $firmantes,
            'sello' => $sello,
            'acento' => $acento,
            'acento_suave' => $this->aclarar($acento),
        ])->render();

        return $this->pdf->generar($html, [
            'titulo' => $diseno->titulo,
            'papel' => $diseno->tamano_papel,
            'orientacion' => $diseno->orientacion,
            'membrete' => $this->membrete($armado, $logo, $acento),
            'pie' => $this->pie($armado),
            'marca_agua' => $armado['marca_agua'] ?? null,
            'marca_agua_opacidad' => $diseno->marca_agua_opacidad,
            /*
             * Los márgenes salen del DISEÑO, en milímetros.
             *
             * Antes se subía el de arriba a 40 cuando había logo y a 32 cuando
             * no. Eso resolvía el encimado del membrete pero le quitaba a la
             * escuela la decisión: quien imprime sobre papel ya membretado
             * necesita 60, y no había dónde pedirlo. Ahora el valor por omisión
             * ya contempla el logo y quien no lo usa lo baja.
             */
            'margen_superior' => $diseno->margen_superior,
            'margen_inferior' => $diseno->margen_inferior,
            'margen_izquierdo' => $diseno->margen_izquierdo,
            'margen_derecho' => $diseno->margen_derecho,
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
    private function membrete(array $armado, ?string $logo, ?string $acento): string
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

        return '<table width="100%" style="border-bottom:0.6pt solid '.($acento ?: '#333').';padding-bottom:3pt">
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
     * El acento que la escuela ya eligió para su plataforma.
     *
     * ── Por qué no es un color propio del historial ───────────────────────
     * Sería pedir dos veces lo mismo y garantizar que alguien los
     * desincronice. El documento tenía `#eef2f7` y `#64748b` cableados, que es
     * exactamente el defecto por el que este proyecto ya retiró el morado fijo
     * de 31 sitios.
     *
     * Se lee del tema PREDETERMINADO de la escuela y no del tema del usuario:
     * el historial de una alumna no puede salir de otro color porque quien lo
     * imprimió tenga puesto el tema oscuro.
     */
    private function acentoDeLaEscuela(): ?string
    {
        $valor = TemaToken::query()
            ->where('token', 'acento')
            ->whereIn('tema_id', Tema::query()->where('es_default', true)->select('id'))
            ->value('valor');

        // Sólo hexadecimal: este valor entra en el HTML del documento, y aunque
        // hoy lo escribe el catálogo de temas, un color que se pega dentro de un
        // atributo `style` sin comprobar es la clase de hueco que se aprovecha
        // cuando mañana ese campo lo llene otra pantalla.
        return is_string($valor) && preg_match('/^#[0-9A-Fa-f]{6}$/', $valor) ? $valor : null;
    }

    /**
     * El mismo color, mezclado con blanco, para usarlo de fondo.
     *
     * Se calcula aquí y no con un hexadecimal de ocho dígitos (`#RRGGBBAA`):
     * el canal alfa en hexadecimal no es fiable en mpdf, y un color que el
     * motor no entiende se descarta en silencio dejando el fondo transparente.
     */
    private function aclarar(?string $hex, float $peso = 0.12): ?string
    {
        if ($hex === null) {
            return null;
        }

        [$r, $g, $b] = sscanf($hex, '#%02x%02x%02x');

        return sprintf(
            '#%02X%02X%02X',
            (int) round(255 - (255 - $r) * $peso),
            (int) round(255 - (255 - $g) * $peso),
            (int) round(255 - (255 - $b) * $peso),
        );
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
