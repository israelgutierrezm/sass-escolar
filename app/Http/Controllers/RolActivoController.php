<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Identidad\Usuario;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Conmutador de rol: cambia el rol con el que la persona interactúa, sin
 * cerrar sesión.
 *
 * El cambio es de CONTEXTO, no de identidad: la misma persona pasa de ver la
 * escuela como encargada de admisiones a verla como docente. `Usuario::conmutarRol`
 * valida que el rol pedido esté entre sus roles activos, así que un id
 * manipulado desde el cliente se rechaza.
 */
class RolActivoController extends Controller
{
    public function actualizar(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'rol_id' => ['required', 'integer'],
        ]);

        /** @var Usuario $usuario */
        $usuario = Auth::user();

        if (! $usuario->conmutarRol((int) $datos['rol_id'])) {
            return back()->with('error', 'Ese rol no está disponible para tu cuenta.');
        }

        $usuario->refresh()->loadMissing('rolActivo');

        return back()->with('exito', "Ahora estás usando el sistema como {$usuario->rolActivo->nombre}.");
    }
}
