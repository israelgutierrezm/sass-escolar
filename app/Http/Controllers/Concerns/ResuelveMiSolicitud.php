<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Models\Admisiones\Aspirante;
use Illuminate\Http\Request;

/**
 * La solicitud de admisión de quien está en sesión.
 *
 * El alcance NO viene por la URL: se deduce de la persona autenticada, así que
 * no hay forma de pedir el expediente de otro cambiando un id. Es la diferencia
 * entre las pantallas del portal y las administrativas, y por eso vive en un
 * trait: en cuanto dos controladores la necesitan, copiarla es cómo se llega a
 * que una revise la propiedad y la otra se olvide.
 */
trait ResuelveMiSolicitud
{
    /**
     * Si su persona no tiene aspirante, no hay portal que mostrar — le pasa a
     * quien conserva el rol pero ya se convirtió en alumno, o a quien se lo
     * asignaron por error.
     */
    protected function miSolicitud(Request $request): Aspirante
    {
        $aspirante = Aspirante::query()
            ->where('persona_id', $request->user()->persona_id)
            ->orderByDesc('id')
            ->first();

        abort_if($aspirante === null, 404, 'No tienes una solicitud de admisión abierta.');

        return $aspirante;
    }
}
