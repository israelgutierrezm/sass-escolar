<?php

declare(strict_types=1);

namespace App\Services\Videoconferencia;

use App\Models\Lms\CuentaVideo;
use App\Models\Lms\Videoconferencia;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

/**
 * El proveedor que no sale a internet.
 *
 * Sirve para recorrer el flujo entero sin credenciales: programar, ver el
 * reparto de licencias, comprobar que al alumno le aparece el botón a su hora y
 * cancelar. Lo enciende `VIDEO_MODO=fake`.
 *
 * ── Devuelve enlaces DISTINTOS para anfitrión e invitado ───────────────────
 * Aunque ninguno lleve a ninguna parte. Si devolviera el mismo, la prueba de que
 * el enlace de anfitrión no se le filtra al alumno pasaría siempre —comparando
 * una cadena consigo misma— y no probaría nada. Es la misma trampa de la
 * credencial que dibujaba sólo el nombre.
 */
class ProveedorFalso implements Proveedor
{
    public function crear(
        CuentaVideo $cuenta,
        string $titulo,
        CarbonInterface $inicio,
        int $minutos,
    ): SesionCreada {
        $id = 'fake-'.Str::lower(Str::random(10));

        return new SesionCreada(
            meetingId: $id,
            urlInvitado: url("/simulacion/clase/{$id}"),
            urlAnfitrion: url("/simulacion/clase/{$id}?anfitrion=".Str::random(24)),
        );
    }

    public function cancelar(Videoconferencia $sesion): void
    {
        // No hay nada del otro lado que cancelar.
    }
}
