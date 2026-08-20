<?php

declare(strict_types=1);

namespace App\Services\Grabaciones;

use Illuminate\Support\Str;

/**
 * El destino que no sale a internet.
 *
 * Copia al disco local igual que `DestinoDisco` —para que el archivo exista de
 * verdad y se pueda comprobar que se descargó entero— pero devuelve una URL
 * inventada, como haría un destino externo. Así la prueba del flujo completo no
 * necesita credenciales de Drive ni de Dropbox.
 */
class DestinoFalso implements Destino
{
    public function __construct(private readonly DestinoDisco $disco) {}

    public function subir(string $rutaLocal, string $nombre, string $carpeta): ArchivoArchivado
    {
        $guardado = $this->disco->subir($rutaLocal, $nombre, $carpeta);

        return new ArchivoArchivado(
            ruta: $guardado->ruta,
            url: 'https://simulacion.invalido/grabaciones/'.Str::lower(Str::random(12)),
            bytes: $guardado->bytes,
        );
    }
}
