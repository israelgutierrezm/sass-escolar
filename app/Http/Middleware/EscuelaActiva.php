<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Corta el acceso a una escuela SUSPENDIDA por la casa.
 *
 * La casa (panel central) puede marcar `suspendida` en el tenant sin borrar
 * nada. Este middleware —que corre después de inicializar el tenant— detiene
 * cualquier petición a esa escuela (incluido su login) con una página propia.
 */
class EscuelaActiva
{
    public function handle(Request $request, Closure $next): Response
    {
        if (tenant() !== null && (bool) (tenant()->suspendida ?? false)) {
            return response()->view('escuela-suspendida', [], 503);
        }

        return $next($request);
    }
}
