<?php

declare(strict_types=1);

namespace App\Services\Grabaciones;

/**
 * Dónde quedó el archivo después de subirlo.
 *
 * `ruta` es cómo lo encuentra el sistema —la ruta en el disco, el id en Drive—
 * y `url` es a dónde se manda a una persona. No siempre coinciden y a veces sólo
 * hay una: en el disco propio la URL la construye Acadion (una ruta suya que
 * comprueba permisos), y en Drive el id sirve para pedirlo por API aunque el
 * enlace público no exista.
 */
final class ArchivoArchivado
{
    public function __construct(
        public readonly string $ruta,
        public readonly ?string $url = null,
        public readonly ?int $bytes = null,
    ) {}
}
