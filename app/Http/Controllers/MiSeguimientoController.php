<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Permanencia\MiSeguimiento;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Lo que el alumno ve de sí mismo, y a dónde ir con ello.
 *
 * ── La ruta NO lleva id ───────────────────────────────────────────────────
 * La persona sale de la sesión, así que no hay dónde escribir la de otro. Quien
 * estudia dos programas elige entre los suyos, y un id ajeno cae en el propio
 * — sin 403, que un 403 confirmaría que ese id existe. Es el molde de
 * `/mi-historial` y `/mi-credencial`.
 */
class MiSeguimientoController extends Controller
{
    public function __invoke(Request $peticion, MiSeguimiento $seguimiento): Response
    {
        return Inertia::render('MiSeguimiento', $seguimiento->de(
            $peticion->user(),
            $peticion->filled('matricula') ? (int) $peticion->input('matricula') : null,
        ));
    }
}
