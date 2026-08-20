<?php

declare(strict_types=1);

namespace App\Services\Grabaciones;

/**
 * Lo que Acadion necesita de un sitio donde guardar grabaciones.
 *
 * Una sola cosa: recibir un archivo que ya está en disco y devolver dónde quedó.
 *
 * ── Recibe una RUTA LOCAL, no un flujo ni una URL ──────────────────────────
 * Quien descarga de Zoom es `ArchivarGrabacion`, y lo hace a un archivo temporal
 * por trozos. Si cada destino recibiera la URL de origen, los tres tendrían que
 * saber autenticarse contra Zoom y contra Meet —y el día que entre un tercer
 * proveedor de video habría que tocar los tres destinos—. Con un archivo en
 * disco, el destino sólo sabe subir.
 *
 * ── Y por eso no se transmite «al vuelo» ───────────────────────────────────
 * Sería más elegante enchufar la descarga con la subida sin tocar el disco, y
 * sería frágil: un corte a la mitad dejaría medio archivo en el destino sin
 * forma de reanudar, y ni Drive ni Dropbox aceptan una subida reanudable sin
 * conocer el tamaño de antemano. Con el archivo completo en disco, un reintento
 * vuelve a subir lo mismo y no vuelve a bajarlo.
 */
interface Destino
{
    /**
     * Sube el archivo y devuelve dónde quedó.
     *
     * Puede lanzar `AvisoParaElUsuario`: quien reintenta a mano necesita leer
     * por qué falló —«sin espacio», «carpeta no encontrada»— y no un 500.
     *
     * @param  string  $rutaLocal  archivo ya descargado, en el disco del servidor
     * @param  string  $nombre  con el que debe quedar guardado
     * @param  string  $carpeta  subcarpeta sugerida (la materia y el ciclo)
     */
    public function subir(string $rutaLocal, string $nombre, string $carpeta): ArchivoArchivado;
}
