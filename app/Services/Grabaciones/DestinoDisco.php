<?php

declare(strict_types=1);

namespace App\Services\Grabaciones;

use App\Exceptions\AvisoParaElUsuario;
use Illuminate\Support\Facades\Storage;

/**
 * El almacenamiento de la propia escuela: el disco privado de siempre.
 *
 * Es el destino que permite empezar a archivar hoy, sin contratar ni conectar
 * nada. Y es el único cuyo enlace NO es del proveedor: la URL la sirve Acadion,
 * que es quien puede comprobar que quien pide el archivo es de ese grupo. Un
 * enlace de Drive o de Dropbox, una vez compartido, lo abre cualquiera que lo
 * tenga; éste no.
 */
class DestinoDisco implements Destino
{
    public function subir(string $rutaLocal, string $nombre, string $carpeta): ArchivoArchivado
    {
        $manejador = fopen($rutaLocal, 'rb');

        AvisoParaElUsuario::si($manejador === false, 500, 'No se pudo leer el archivo descargado.');

        // `putFileAs` con un flujo y no `put` con el contenido: un video de dos
        // horas en memoria tumba el proceso.
        $ruta = Storage::disk('local')->putFileAs("grabaciones/{$carpeta}", $rutaLocal, $nombre);

        if (is_resource($manejador)) {
            fclose($manejador);
        }

        AvisoParaElUsuario::si($ruta === false, 500, 'No se pudo guardar la grabación en el disco de la escuela.');

        return new ArchivoArchivado(
            ruta: $ruta,
            // Sin URL del proveedor: la sirve Acadion comprobando quién pide.
            url: null,
            bytes: Storage::disk('local')->size($ruta),
        );
    }
}
