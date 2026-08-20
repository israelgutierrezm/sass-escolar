<?php

declare(strict_types=1);

namespace App\Services\Videoconferencia;

/**
 * Lo que un proveedor devuelve al crear la reunión.
 *
 * Los dos enlaces NO son intercambiables y por eso van en campos distintos con
 * nombres que lo dicen: `urlInvitado` es para el grupo y `urlAnfitrion` entra
 * como dueño de la sala. Un solo campo `url` habría acabado, tarde o temprano,
 * con el enlace de anfitrión pintado en la pantalla de un alumno.
 */
final class SesionCreada
{
    public function __construct(
        /** Con qué la conoce el proveedor, para volver a preguntarle. */
        public readonly string $meetingId,
        /** El que se le da al grupo. */
        public readonly string $urlInvitado,
        /** El que abre como anfitrión. Sólo para quien da la clase. */
        public readonly ?string $urlAnfitrion = null,
    ) {}
}
