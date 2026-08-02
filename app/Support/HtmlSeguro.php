<?php

declare(strict_types=1);

namespace App\Support;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Deja el HTML de un editor en condiciones de pintarse en la pantalla de otro.
 *
 * ── Por qué hace falta ─────────────────────────────────────────────────────
 * El contenido de una lección lo escribe el docente y lo LEE el alumno, con el
 * navegador interpretando las etiquetas. Sin limpiar, un `<img onerror="...">`
 * guardado en una actividad se ejecuta en la sesión de cada alumno del grupo:
 * XSS almacenado, de los de manual.
 *
 * No basta con que el editor se porte bien. TipTap sólo emite etiquetas de su
 * esquema, pero lo que llega al servidor es un POST, y un POST se arma con
 * cualquier cosa. El saneado va aquí porque aquí es donde no se puede saltar.
 *
 * ── Cómo ───────────────────────────────────────────────────────────────────
 * Lista blanca, no lista negra. Se enumera lo que puede quedarse y lo demás se
 * cae —etiquetas y atributos—: una lista de lo prohibido siempre va un paso
 * atrás del que inventa la siguiente forma de colar algo.
 *
 * El `iframe` se conserva porque es lo que permite incrustar un video o un
 * SCORM que la escuela ya tenga producido, pero sale con `sandbox` y
 * `referrerpolicy` puestos por el servidor: si el HTML venía sin ellos —o con
 * un `sandbox` vacío, que lo permite todo— se le imponen igual.
 */
class HtmlSeguro
{
    /** Lo que puede quedarse, con sus atributos permitidos. */
    private const PERMITIDO = [
        'p' => ['style'],
        'br' => [],
        'strong' => [], 'b' => [], 'em' => [], 'i' => [], 'u' => [], 's' => [],
        'h1' => [], 'h2' => [], 'h3' => [], 'h4' => [],
        'ul' => [], 'ol' => ['start'], 'li' => [],
        'blockquote' => [], 'hr' => [],
        'a' => ['href', 'title', 'target', 'rel'],
        'img' => ['src', 'alt', 'title', 'width', 'height'],
        'iframe' => ['src', 'width', 'height', 'title', 'class', 'frameborder', 'allowfullscreen', 'sandbox', 'referrerpolicy'],
        'table' => [], 'thead' => [], 'tbody' => [], 'tr' => [], 'th' => ['colspan', 'rowspan'], 'td' => ['colspan', 'rowspan'],
        'code' => [], 'pre' => [], 'span' => ['style'], 'div' => ['style'], 'figure' => [], 'figcaption' => [],
    ];

    /**
     * Lo único que se deja de `style`: la alineación que pone el editor.
     *
     * Un `style` libre alcanza para tapar la pantalla entera con un bloque
     * posicionado encima de los botones. La alineación es lo que el editor
     * escribe y lo que un texto necesita.
     */
    private const ESTILO_PERMITIDO = '/^text-align:\s*(left|right|center|justify);?$/i';

    public static function limpiar(?string $html): ?string
    {
        if (blank($html)) {
            return null;
        }

        $documento = new DOMDocument;

        /*
         * `mb_convert_encoding` no: está deprecada para esto y rompe emojis. El
         * prefijo XML le dice a DOMDocument que el texto viene en UTF-8, que es
         * lo que llega del navegador.
         */
        $previo = libxml_use_internal_errors(true);
        $documento->loadHTML(
            '<?xml encoding="UTF-8"><div id="raiz-html-seguro">'.$html.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previo);

        $raiz = $documento->getElementById('raiz-html-seguro');

        if ($raiz === null) {
            return null;
        }

        self::limpiarNodo($raiz);

        $salida = '';

        foreach ($raiz->childNodes as $hijo) {
            $salida .= $documento->saveHTML($hijo);
        }

        $salida = trim($salida);

        /*
         * Un editor «vacío» no devuelve una cadena vacía: devuelve `<p></p>`, o
         * `<p><br></p>` si se pulsó Enter. Guardado tal cual, `tieneContenido()`
         * diría que la actividad trae material que leer y el alumno abriría una
         * lección en blanco.
         *
         * Vacío es no tener NI texto NI algo que mirar. La imagen y el iframe
         * cuentan aunque no traigan una sola letra: una lección puede ser un
         * video y nada más.
         */
        $texto = trim(str_replace("\u{A0}", ' ', strip_tags($salida)));
        $medios = (bool) preg_match('/<(img|iframe|hr)\b/i', $salida);

        return $texto === '' && ! $medios ? null : $salida;
    }

    /** Recorre el árbol de abajo hacia arriba quitando lo que no está en la lista. */
    private static function limpiarNodo(DOMNode $nodo): void
    {
        // Al revés porque quitar un hijo reindexa la lista viva de childNodes:
        // hacia adelante se saltaría uno de cada dos.
        for ($i = $nodo->childNodes->length - 1; $i >= 0; $i--) {
            $hijo = $nodo->childNodes->item($i);

            if ($hijo === null) {
                continue;
            }

            if ($hijo instanceof DOMElement) {
                self::limpiarElemento($hijo);

                continue;
            }

            // Comentarios y bloques CDATA fuera: no aportan y son un escondite.
            if ($hijo->nodeType === XML_COMMENT_NODE || $hijo->nodeType === XML_CDATA_SECTION_NODE) {
                $nodo->removeChild($hijo);
            }
        }
    }

    private static function limpiarElemento(DOMElement $elemento): void
    {
        $etiqueta = strtolower($elemento->nodeName);

        if (! array_key_exists($etiqueta, self::PERMITIDO)) {
            /*
             * Se quita la etiqueta pero se conserva su texto: un `<font>` de un
             * pegado de Word no debe borrar el párrafo que envuelve. La
             * excepción son los que sólo traen código.
             */
            $conservarDentro = ! in_array($etiqueta, ['script', 'style', 'iframe', 'object', 'embed', 'form'], true);

            if ($conservarDentro) {
                self::limpiarNodo($elemento);

                while ($elemento->firstChild !== null) {
                    $elemento->parentNode?->insertBefore($elemento->firstChild, $elemento);
                }
            }

            $elemento->parentNode?->removeChild($elemento);

            return;
        }

        $permitidos = self::PERMITIDO[$etiqueta];

        for ($i = $elemento->attributes->length - 1; $i >= 0; $i--) {
            $atributo = $elemento->attributes->item($i);

            if ($atributo === null) {
                continue;
            }

            $nombre = strtolower($atributo->nodeName);

            if (! in_array($nombre, $permitidos, true)) {
                $elemento->removeAttribute($atributo->nodeName);

                continue;
            }

            if (! self::valorAceptable($nombre, $atributo->nodeValue ?? '')) {
                $elemento->removeAttribute($atributo->nodeName);
            }
        }

        if ($etiqueta === 'iframe') {
            self::asegurarIframe($elemento);
        }

        if ($etiqueta === 'a' && $elemento->getAttribute('target') === '_blank') {
            // Sin esto, la página abierta puede manipular la de origen.
            $elemento->setAttribute('rel', 'noopener noreferrer');
        }

        self::limpiarNodo($elemento);
    }

    /** Direcciones y estilos: lo que puede llevar dentro un atributo permitido. */
    private static function valorAceptable(string $atributo, string $valor): bool
    {
        if ($atributo === 'style') {
            return (bool) preg_match(self::ESTILO_PERMITIDO, trim($valor));
        }

        if (! in_array($atributo, ['href', 'src'], true)) {
            return true;
        }

        $limpio = strtolower(trim(preg_replace('/\s+/', '', $valor) ?? ''));

        // `javascript:`, `data:` y `vbscript:` son las tres formas clásicas de
        // meter código donde se espera una dirección.
        foreach (['javascript:', 'vbscript:', 'data:'] as $prohibido) {
            if (str_starts_with($limpio, $prohibido)) {
                return false;
            }
        }

        return true;
    }

    /**
     * El iframe se queda, pero encerrado.
     *
     * `sandbox` se impone siempre —aunque venga uno propio— porque un
     * `sandbox=""` bien puesto por quien arma el POST parece un sandbox y no lo
     * es. Sin `allow-top-navigation`: lo incrustado no saca al alumno de la
     * página, que es el abuso obvio.
     */
    private static function asegurarIframe(DOMElement $iframe): void
    {
        $src = trim($iframe->getAttribute('src'));

        if (! str_starts_with(strtolower($src), 'https://')) {
            $iframe->parentNode?->removeChild($iframe);

            return;
        }

        $iframe->setAttribute('sandbox', 'allow-scripts allow-same-origin allow-forms allow-popups allow-presentation');
        $iframe->setAttribute('referrerpolicy', 'no-referrer');
        $iframe->setAttribute('class', 'incrustado');
        $iframe->setAttribute('width', '100%');
    }
}
