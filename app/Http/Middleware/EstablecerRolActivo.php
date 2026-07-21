<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Identidad\PersonaRol;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resuelve y valida el ROL ACTIVO del usuario en cada request.
 *
 * El `rol_activo_id` no es estado de front: gobierna qué permisos aplican, qué
 * menús y rutas se muestran y qué tema se carga. Por eso se valida en el
 * servidor en cada petición, como defensa contra manipulación del cliente:
 *
 *  - Si el rol activo ya no está entre los roles ACTIVOS de la persona (se lo
 *    revocaron, o el valor fue manipulado), se descarta y se cae al primer rol
 *    disponible.
 *  - Si la persona no tiene ningún rol activo, queda sin rol: las
 *    autorizaciones fallarán cerradas.
 */
class EstablecerRolActivo
{
    public function handle(Request $request, Closure $next): Response
    {
        $usuario = Auth::user();

        if ($usuario === null) {
            return $next($request);
        }

        $rolesValidos = PersonaRol::query()
            ->where('persona_id', $usuario->persona_id)
            ->where('activo', true)
            ->pluck('rol_id')
            ->unique();

        if ($rolesValidos->isEmpty()) {
            if ($usuario->rol_activo_id !== null) {
                $usuario->forceFill(['rol_activo_id' => null])->save();
            }

            return $next($request);
        }

        if (! $rolesValidos->contains($usuario->rol_activo_id)) {
            $usuario->forceFill(['rol_activo_id' => $rolesValidos->first()])->save();
        }

        return $next($request);
    }
}
