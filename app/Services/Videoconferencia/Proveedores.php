<?php

declare(strict_types=1);

namespace App\Services\Videoconferencia;

use App\Exceptions\AvisoParaElUsuario;
use App\Models\Lms\IntegracionVideo;
use App\Support\ProveedoresVideoCatalogo;

/**
 * Qué implementación atiende a cada proveedor.
 *
 * Mismo papel que `Pasarelas` en el cobro, y con el mismo orden de
 * comprobaciones, que no es casual:
 *
 * 1. Que el proveedor exista en el catálogo.
 * 2. El modo `fake`, ANTES de mirar credenciales: existe justamente para
 *    trabajar sin ellas.
 * 3. Que esté encendido y con credenciales completas.
 *
 * Para agregar uno nuevo: implementar `Proveedor`, declararlo en
 * `ProveedoresVideoCatalogo` y añadirlo aquí. Lo que NO hay que hacer es darlo
 * por bueno sin ejercitarlo: una clase que parece programada y no existe del
 * otro lado deja a un grupo entero esperando frente a un enlace muerto, y nadie
 * se entera hasta la hora.
 */
class Proveedores
{
    public function __construct(
        private readonly ProveedorZoom $zoom,
        private readonly ProveedorMeet $meet,
    ) {}

    /** El proveedor listo para operar, o un aviso de por qué no se puede. */
    public function para(IntegracionVideo $integracion): Proveedor
    {
        AvisoParaElUsuario::aMenosQue(
            ProveedoresVideoCatalogo::existe($integracion->clave),
            404,
            'Ese proveedor de clase en línea no existe.',
        );

        if (config('video.modo') === 'fake') {
            return new ProveedorFalso;
        }

        AvisoParaElUsuario::aMenosQue(
            $integracion->activa,
            422,
            'Ese proveedor está apagado. Enciéndelo en Plataforma › Clases en línea.',
        );

        AvisoParaElUsuario::aMenosQue(
            $integracion->credencialesCompletas(),
            422,
            'A ese proveedor le faltan credenciales. Complétalas en Plataforma › Clases en línea.',
        );

        return match ($integracion->clave) {
            ProveedoresVideoCatalogo::ZOOM => $this->zoom->con($integracion),
            ProveedoresVideoCatalogo::MEET => $this->meet->con($integracion),
        };
    }
}
