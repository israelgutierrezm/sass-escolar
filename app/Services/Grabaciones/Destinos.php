<?php

declare(strict_types=1);

namespace App\Services\Grabaciones;

use App\Exceptions\AvisoParaElUsuario;
use App\Models\Lms\DestinoGrabacion;
use App\Support\DestinosGrabacionCatalogo;

/**
 * Qué implementación atiende a cada destino.
 *
 * Mismo orden de comprobaciones que en las pasarelas y los proveedores de video:
 * que exista, el modo `fake` antes que las credenciales, y luego que esté
 * encendido y completo.
 *
 * El DISCO se salta el modo falso a propósito: no sale a internet, no tiene
 * credenciales y guardar de verdad es más útil que simularlo — una prueba con el
 * disco comprueba que el archivo llegó entero.
 */
class Destinos
{
    public function __construct(private readonly DestinoDisco $disco) {}

    public function para(DestinoGrabacion $config): Destino
    {
        AvisoParaElUsuario::aMenosQue(
            DestinosGrabacionCatalogo::existe($config->clave),
            404,
            'Ese destino de grabaciones no existe.',
        );

        if ($config->clave === DestinosGrabacionCatalogo::DISCO) {
            return $this->disco;
        }

        if (config('video.modo') === 'fake') {
            return new DestinoFalso($this->disco);
        }

        AvisoParaElUsuario::aMenosQue(
            $config->credencialesCompletas(),
            422,
            'A ese destino le faltan credenciales. Complétalas en Plataforma › Clases en línea.',
        );

        return match ($config->clave) {
            DestinosGrabacionCatalogo::DRIVE => new DestinoDrive($config),
            DestinosGrabacionCatalogo::DROPBOX => new DestinoDropbox($config),
        };
    }
}
