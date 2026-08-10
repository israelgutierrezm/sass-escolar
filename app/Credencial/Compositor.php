<?php

declare(strict_types=1);

namespace App\Credencial;

use App\Models\Identidad\CredencialRol;
use GdImage;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Dibuja una cara de la credencial y devuelve su PNG.
 *
 * ── Por qué se compone en el servidor y no en el navegador ─────────────────
 * Porque el resultado tiene que ser el MISMO archivo para todos: el que ve la
 * persona en pantalla, el que se descarga y el que se imprime. Componiendo con
 * CSS, cada navegador y cada zoom daría un recorte distinto, y «descargar» sería
 * una captura de pantalla con la tipografía que ese equipo tuviera instalada.
 *
 * ── El sistema de coordenadas ──────────────────────────────────────────────
 * Todo se mapea en PORCENTAJE del lienzo, no en píxeles. Así el mismo mapa
 * sirve para una credencial de 1011×638 y para la misma a otra resolución, y la
 * pantalla de configuración —que enseña el machote reducido— puede escribir las
 * posiciones sin saber a qué tamaño se va a dibujar. En píxeles habría que
 * reconvertir el mapa entero cada vez que alguien cambia el tamaño.
 */
class Compositor
{
    /** Dónde vive la tipografía. Ver `resources/fuentes/LEEME.md`. */
    private const FUENTE = 'fuentes/OpenSans.ttf';

    public function __construct(private readonly Disenos $disenos) {}

    /**
     * Compone una cara.
     *
     * La foto y el QR llegan como BINARIO, no como ruta: la vista previa de la
     * pantalla de configuración dibuja una silueta inventada que no está en
     * ningún disco, y hacerla escribir un archivo temporal para poder enseñarla
     * sería basura en el servidor por cada arrastre de una caja. La firma sí se
     * lee del disco porque es parte de la configuración, no del titular.
     *
     * @param  array<string, string>  $valores  lo que resolvió `CatalogoCampos`
     */
    public function componer(
        CredencialRol $config,
        string $cara,
        array $valores,
        ?string $pngFoto = null,
        ?string $pngQr = null,
    ): string {
        $lienzo = $this->lienzo($config, $cara);

        foreach ($config->{"campos_{$cara}"} ?? [] as $campo) {
            $this->dibujarCampo($lienzo, $config, $campo, $valores, $pngFoto, $pngQr);
        }

        return $this->aPng($lienzo);
    }

    /**
     * El fondo: la imagen del machote, o el diseño que se haya elegido.
     *
     * Si el machote no se puede leer —lo borraron del disco, se subió algo que
     * no es imagen— se cae al lienzo liso en vez de reventar. Una credencial sin
     * su fondo sigue sirviendo para identificar a alguien; una excepción en
     * medio de la descarga, no.
     */
    private function lienzo(CredencialRol $config, string $cara): GdImage
    {
        $ruta = $config->{"machote_{$cara}"};

        if ($config->usaMachotePropio() && filled($ruta) && Storage::disk('local')->exists($ruta)) {
            $imagen = @imagecreatefromstring(Storage::disk('local')->get($ruta));

            if ($imagen !== false) {
                return $this->escalar($imagen, $config->ancho, $config->alto);
            }
        }

        return $config->usaMachotePropio()
            ? $this->liso($config->ancho, $config->alto)
            : $this->disenos->fondo($config, $cara);
    }

    private function liso(int $ancho, int $alto): GdImage
    {
        $lienzo = imagecreatetruecolor($ancho, $alto);
        imagefill($lienzo, 0, 0, imagecolorallocate($lienzo, 255, 255, 255));

        return $lienzo;
    }

    /** El machote se ajusta al lienzo, para que el mapa en porcentaje cuadre. */
    private function escalar(GdImage $origen, int $ancho, int $alto): GdImage
    {
        $destino = imagecreatetruecolor($ancho, $alto);
        imagecopyresampled(
            $destino, $origen,
            0, 0, 0, 0,
            $ancho, $alto,
            imagesx($origen), imagesy($origen),
        );
        imagedestroy($origen);

        return $destino;
    }

    /**
     * @param  array<string, mixed>  $campo
     * @param  array<string, string>  $valores
     */
    private function dibujarCampo(
        GdImage $lienzo,
        CredencialRol $config,
        array $campo,
        array $valores,
        ?string $pngFoto,
        ?string $pngQr,
    ): void {
        $clave = $campo['clave'] ?? null;

        if ($clave === null) {
            return;
        }

        $caja = $this->caja($campo, imagesx($lienzo), imagesy($lienzo));

        match (true) {
            $clave === CatalogoCampos::FOTO => $this->pegar($lienzo, $pngFoto, $caja),
            $clave === CatalogoCampos::QR => $this->pegar($lienzo, $pngQr, $caja, recortar: false),
            $clave === CatalogoCampos::FIRMA => $this->dibujarImagen($lienzo, $caja, $config->firma_imagen, recortar: false),
            default => $this->dibujarTexto($lienzo, $campo, $caja, $valores),
        };
    }

    /**
     * De porcentajes a píxeles.
     *
     * @param  array<string, mixed>  $campo
     * @return array{x: int, y: int, ancho: int, alto: int}
     */
    private function caja(array $campo, int $ancho, int $alto): array
    {
        return [
            'x' => (int) round(((float) ($campo['x'] ?? 0)) / 100 * $ancho),
            'y' => (int) round(((float) ($campo['y'] ?? 0)) / 100 * $alto),
            'ancho' => (int) round(((float) ($campo['ancho'] ?? 20)) / 100 * $ancho),
            'alto' => (int) round(((float) ($campo['alto'] ?? 10)) / 100 * $alto),
        ];
    }

    /**
     * Texto: su etiqueta encima —si la lleva— y el valor debajo.
     *
     * La etiqueta es lo que en las credenciales reales dice «ALUMNO» o
     * «DOCTORADO EN» sobre el dato, más chica y en otro color. Y si el campo NO
     * APLICA a esta persona no se dibuja nada, ni siquiera la etiqueta: un
     * «MATRÍCULA» sin número debajo es peor que el hueco vacío.
     *
     * @param  array<string, mixed>  $campo
     * @param  array{x: int, y: int, ancho: int, alto: int}  $caja
     * @param  array<string, string>  $valores
     */
    private function dibujarTexto(GdImage $lienzo, array $campo, array $caja, array $valores): void
    {
        $valor = $valores[$campo['clave']] ?? null;

        if (blank($valor)) {
            return;
        }

        $fuente = $this->fuente();
        $color = $this->color($lienzo, $campo['color'] ?? '#111111');
        $tamano = max(6, (int) ($campo['tamano'] ?? 18));
        $y = $caja['y'];

        if (filled($campo['etiqueta'] ?? null)) {
            $tamanoEtiqueta = max(5, (int) round($tamano * 0.62));
            $y += $tamanoEtiqueta;
            imagettftext(
                $lienzo, $tamanoEtiqueta, 0,
                $this->x($lienzo, $fuente, $tamanoEtiqueta, (string) $campo['etiqueta'], $campo, $caja),
                $y,
                $this->color($lienzo, $campo['color_etiqueta'] ?? '#6b7280'),
                $fuente,
                (string) $campo['etiqueta'],
            );
            $y += (int) round($tamano * 0.5);
        }

        // Se parte en renglones para que un nombre largo no se salga de su caja.
        foreach ($this->renglones($fuente, $tamano, $valor, $caja['ancho']) as $renglon) {
            $y += $tamano;
            imagettftext($lienzo, $tamano, 0, $this->x($lienzo, $fuente, $tamano, $renglon, $campo, $caja), $y, $color, $fuente, $renglon);
            $y += (int) round($tamano * 0.35);
        }
    }

    /**
     * La equis según la alineación pedida.
     *
     * @param  array<string, mixed>  $campo
     * @param  array{x: int, y: int, ancho: int, alto: int}  $caja
     */
    private function x(GdImage $lienzo, string $fuente, int $tamano, string $texto, array $campo, array $caja): int
    {
        $alineacion = $campo['alineacion'] ?? 'izquierda';

        if ($alineacion === 'izquierda') {
            return $caja['x'];
        }

        $ancho = $this->anchoDe($fuente, $tamano, $texto);

        return $alineacion === 'centro'
            ? $caja['x'] + (int) round(($caja['ancho'] - $ancho) / 2)
            : $caja['x'] + $caja['ancho'] - $ancho;
    }

    /**
     * Parte el texto en renglones que quepan en el ancho de la caja.
     *
     * @return array<int, string>
     */
    private function renglones(string $fuente, int $tamano, string $texto, int $ancho): array
    {
        $palabras = preg_split('/\s+/', trim($texto)) ?: [];
        $renglones = [];
        $actual = '';

        foreach ($palabras as $palabra) {
            $prueba = $actual === '' ? $palabra : "{$actual} {$palabra}";

            if ($actual !== '' && $this->anchoDe($fuente, $tamano, $prueba) > $ancho) {
                $renglones[] = $actual;
                $actual = $palabra;

                continue;
            }

            $actual = $prueba;
        }

        if ($actual !== '') {
            $renglones[] = $actual;
        }

        return $renglones;
    }

    private function anchoDe(string $fuente, int $tamano, string $texto): int
    {
        $caja = imagettfbbox($tamano, 0, $fuente, $texto);

        return (int) abs($caja[2] - $caja[0]);
    }

    /** Una imagen del disco privado, dentro de su caja. */
    private function dibujarImagen(GdImage $lienzo, array $caja, ?string $ruta, bool $recortar = true): void
    {
        if (blank($ruta) || ! Storage::disk('local')->exists($ruta)) {
            return;
        }

        $this->pegar($lienzo, Storage::disk('local')->get($ruta), $caja, $recortar);
    }

    /**
     * Pega una imagen en su caja sin deformarla, de una de dos maneras.
     *
     * Nunca se estira: una foto de rostro estirada para caber en un hueco
     * cuadrado deja a la persona irreconocible, que es lo contrario de para qué
     * sirve una credencial. Lo que cambia es qué se sacrifica.
     *
     * ── `recortar: true` — llenar la caja ──────────────────────────────────
     * La foto. Se agranda hasta cubrir el hueco y sobra imagen por dos lados,
     * que se van. Es lo que se quiere en un retrato: el hueco queda lleno y lo
     * que se pierde son los bordes.
     *
     * ── `recortar: false` — caber entera ───────────────────────────────────
     * El QR y la firma. Aquí recortar no es un detalle estético: un código al
     * que le falta una esquina PIERDE sus patrones de posición y deja de
     * leerse, y una firma cortada por la mitad ya no es esa firma. Se encoge
     * hasta caber completa y se centra, sobrando lienzo en vez de imagen.
     *
     * @param  array{x: int, y: int, ancho: int, alto: int}  $caja
     */
    private function pegar(GdImage $lienzo, ?string $binario, array $caja, bool $recortar = true): void
    {
        if (blank($binario)) {
            return;
        }

        $imagen = @imagecreatefromstring($binario);

        if ($imagen === false) {
            return;
        }

        $anchoOrigen = imagesx($imagen);
        $altoOrigen = imagesy($imagen);

        $escala = $recortar
            ? max($caja['ancho'] / $anchoOrigen, $caja['alto'] / $altoOrigen)
            : min($caja['ancho'] / $anchoOrigen, $caja['alto'] / $altoOrigen);

        if ($recortar) {
            // La porción del origen que, escalada, llena la caja exacta.
            $tomaAncho = (int) round($caja['ancho'] / $escala);
            $tomaAlto = (int) round($caja['alto'] / $escala);

            imagecopyresampled(
                $lienzo, $imagen,
                $caja['x'], $caja['y'],
                (int) round(($anchoOrigen - $tomaAncho) / 2),
                (int) round(($altoOrigen - $tomaAlto) / 2),
                $caja['ancho'], $caja['alto'],
                $tomaAncho, $tomaAlto,
            );

            imagedestroy($imagen);

            return;
        }

        $destinoAncho = (int) round($anchoOrigen * $escala);
        $destinoAlto = (int) round($altoOrigen * $escala);

        imagecopyresampled(
            $lienzo, $imagen,
            $caja['x'] + (int) round(($caja['ancho'] - $destinoAncho) / 2),
            $caja['y'] + (int) round(($caja['alto'] - $destinoAlto) / 2),
            0, 0,
            $destinoAncho, $destinoAlto,
            $anchoOrigen, $altoOrigen,
        );

        imagedestroy($imagen);
    }

    private function color(GdImage $lienzo, string $hex): int
    {
        $hex = ltrim($hex, '#');

        if (strlen($hex) !== 6) {
            $hex = '111111';
        }

        return imagecolorallocate(
            $lienzo,
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2)),
        );
    }

    private function fuente(): string
    {
        $ruta = resource_path(self::FUENTE);

        if (! is_file($ruta)) {
            throw new RuntimeException("Falta la tipografía de la credencial en {$ruta}.");
        }

        return $ruta;
    }

    private function aPng(GdImage $lienzo): string
    {
        ob_start();
        imagepng($lienzo);
        $png = (string) ob_get_clean();
        imagedestroy($lienzo);

        return $png;
    }
}
