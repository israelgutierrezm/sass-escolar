<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Plataforma\ModulosDeLaEscuela;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Corta el paso a las rutas de un módulo que la escuela tiene apagado.
 *
 * ── Por qué 404 y no 403 ───────────────────────────────────────────────────
 * Un 403 dice «existe, pero no puedes»: es la respuesta correcta cuando a
 * alguien le falta un permiso, porque le indica a quién pedírselo. Aquí no falta
 * permiso; la sección directamente no forma parte de esta escuela, y anunciar su
 * existencia sólo genera la pregunta de por qué no la tiene.
 *
 * ── Por qué en el middleware y no ocultando el enlace ──────────────────────
 * Esconder el botón del menú deja la dirección viva: quien la tenga guardada, o
 * la haya compartido, sigue entrando. El interruptor tiene que cerrar la puerta,
 * no sólo quitar el letrero.
 */
class ModuloEncendido
{
    public function __construct(private readonly ModulosDeLaEscuela $modulos) {}

    public function handle(Request $peticion, Closure $siguiente, string $clave): Response
    {
        if (! $this->modulos->activo($clave)) {
            throw new NotFoundHttpException;
        }

        return $siguiente($peticion);
    }
}
