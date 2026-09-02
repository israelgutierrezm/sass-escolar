<?php

declare(strict_types=1);

namespace App\Documentos;

use Illuminate\Support\Facades\Storage;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

/**
 * Un documento escolar en PDF, hecho por el servidor.
 *
 * ── Por qué existe, y qué revoca ──────────────────────────────────────────
 * CLAUDE.md decía «en Blade y no en PDF generado: el proyecto no tiene librería
 * de PDF y el navegador ya sabe imprimir». El argumento era de COSTO y era
 * correcto mientras el documento cupiera en una hoja. Un acta cabe; el historial
 * de una egresada son tres, y las tres cosas que le faltaban —membrete por hoja,
 * «Hoja X de Y» y marca de agua en todas— NO son cuestión de esfuerzo: el CSS de
 * paginación que las daría (cajas de margen `@page`, `counter(page)`) no tiene
 * soporte en los motores de impresión de los navegadores. Aquí sí, y son
 * nativas.
 *
 * **Lo que NO revoca**: la copia de VENTANILLA del historial y el acta siguen en
 * Blade con estilos en línea, porque su argumento sigue en pie —que un fallo de
 * assets no deje sin forma un documento en el mostrador—.
 *
 * ── Lo que hay que saber para escribir la maqueta ─────────────────────────
 * mpdf NO entiende `display:flex` ni `display:grid`. Lo que sabe hacer bien son
 * TABLAS y anchos en porcentaje. Una maqueta escrita con flex se dibuja como si
 * cada caja fuera un bloque, o sea apiladas y sin alinear, y no avisa: sale
 * torcido y en silencio. Por eso el historial tiene una vista propia para PDF.
 */
class DocumentoPdf
{
    /**
     * Dónde deja mpdf sus temporales y las fuentes que compila.
     *
     * Va en `storage/app/mpdf` y no en el temporal del sistema: mpdf escribe
     * ahí los datos de cada fuente que usa la primera vez, y en un temporal que
     * el sistema limpia se recompilarían solos cada tanto —lento y sin razón
     * aparente—. Se crea si falta: en un despliegue nuevo no existe.
     */
    private function directorioTemporal(): string
    {
        $ruta = storage_path('app/mpdf');

        if (! is_dir($ruta)) {
            mkdir($ruta, 0775, true);
        }

        return $ruta;
    }

    /**
     * Arma el PDF y devuelve sus bytes.
     *
     * @param  string  $html  el cuerpo, ya renderizado
     * @param  array{
     *     papel?: string, orientacion?: string, membrete?: string, pie?: string,
     *     marca_agua?: string|null, margen_superior?: int, margen_inferior?: int
     * }  $opciones
     */
    public function generar(string $html, array $opciones = []): string
    {
        $mpdf = new Mpdf($this->configuracion($opciones));

        $this->ajustar($mpdf);

        // Metadatos: lo que se ve en las propiedades del archivo y en la pestaña
        // del visor. Sin esto el PDF se llama como el archivo temporal.
        $mpdf->SetCreator('Acadion');

        if (! empty($opciones['titulo'])) {
            $mpdf->SetTitle($opciones['titulo']);
        }

        // El membrete se repite en CADA hoja: es lo que hace que la hoja 3 diga
        // de quién es. Era el defecto principal de la impresión del navegador.
        if (! empty($opciones['membrete'])) {
            $mpdf->SetHTMLHeader($opciones['membrete']);
        }

        if (! empty($opciones['pie'])) {
            $mpdf->SetHTMLFooter($opciones['pie']);
        }

        /*
         * La marca de agua NATIVA, no un elemento de la maqueta.
         *
         * En el Blade era un `position: fixed` que el navegador sólo dibujaba de
         * forma fiable en la primera hoja. Aquí la pone el motor debajo del
         * contenido de TODAS, y no se puede quitar borrando un nodo — que era
         * justo el punto de tenerla.
         */
        if (! empty($opciones['marca_agua'])) {
            $mpdf->SetWatermarkText($opciones['marca_agua']);
            $mpdf->showWatermarkText = true;
            /*
             * La opacidad es DATO y no una constante: 9 % se pierde en una
             * impresora láser vieja y se ve de más en una buena, y quien decide
             * cuánto debe estorbar la marca es la escuela que la va a entregar.
             */
            $mpdf->watermarkTextAlpha = max(1, min(60, (int) ($opciones['marca_agua_opacidad'] ?? 9))) / 100;
        }

        $mpdf->WriteHTML($html);

        return $mpdf->Output('', Destination::STRING_RETURN);
    }

    /**
     * La configuración del motor.
     *
     * Sale a un método propio para que una subclase pueda cambiarla —lo usa la
     * prueba, que necesita una fuente CORE para que el texto quede literal
     * dentro del PDF y se pueda comprobar que el membrete se dibujó en las tres
     * hojas—. Con la fuente normal mpdf escribe índices de glifo de una fuente
     * subconjuntada y no hay nada que buscar.
     *
     * @param  array<string, mixed>  $opciones
     * @return array<string, mixed>
     */
    protected function configuracion(array $opciones): array
    {
        return [
            'tempDir' => $this->directorioTemporal(),
            'format' => $this->formato($opciones['papel'] ?? 'carta', $opciones['orientacion'] ?? 'vertical'),
            /*
             * Los márgenes de arriba y abajo dejan sitio al membrete y al pie
             * REPETIDOS. mpdf los dibuja dentro del margen, así que si el margen
             * es más chico que el membrete, el membrete se encima con la tabla.
             * Por eso son parámetros y no constantes: un membrete con logo pide
             * más que uno de una línea.
             */
            'margin_top' => $opciones['margen_superior'] ?? 40,
            'margin_bottom' => $opciones['margen_inferior'] ?? 18,
            'margin_left' => $opciones['margen_izquierdo'] ?? 12,
            'margin_right' => $opciones['margen_derecho'] ?? 12,
        ];
    }

    /** Punto de extensión: la prueba apaga aquí la compresión para poder leer el PDF. */
    protected function ajustar(Mpdf $mpdf): void {}

    /**
     * El nombre de papel que entiende mpdf, con la orientación pegada.
     *
     * Los tres tamaños son los que ofrece el diseñador. «oficio» es `Legal`:
     * mpdf no conoce esa palabra y con un nombre desconocido cae a A4 en
     * silencio, o sea que el documento sale de otro tamaño sin avisar.
     */
    private function formato(string $papel, string $orientacion): string
    {
        $base = match ($papel) {
            'a4' => 'A4',
            'oficio' => 'Legal',
            // Media carta, para el recibo de ventanilla: una hoja entera para
            // seis renglones se tira a la basura al salir del mostrador.
            'a5' => 'A5',
            default => 'Letter',
        };

        return $orientacion === 'horizontal' ? $base.'-L' : $base;
    }

    /**
     * Una imagen del disco privado como `data:` URI, para incrustarla.
     *
     * ── Por qué no vale la URL ────────────────────────────────────────────
     * El logo, la firma y el sello se sirven hoy por una ruta que exige SESIÓN.
     * mpdf descarga las imágenes él mismo, con su propio cliente HTTP y sin la
     * cookie del usuario, así que pedirlas por URL devolvería la pantalla de
     * acceso y el documento saldría con los huecos vacíos —sin error—. Se leen
     * del disco y se incrustan.
     */
    public function imagenIncrustada(?string $ruta, string $disco = 'local'): ?string
    {
        if ($ruta === null || $ruta === '') {
            return null;
        }

        $almacen = Storage::disk($disco);

        if (! $almacen->exists($ruta)) {
            return null;
        }

        $contenido = $almacen->get($ruta);
        $tipo = $almacen->mimeType($ruta) ?: 'image/png';

        return 'data:'.$tipo.';base64,'.base64_encode($contenido);
    }
}
