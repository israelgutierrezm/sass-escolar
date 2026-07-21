<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Resuelve y valida el rol activo del usuario en cada request del tenant.
        $middleware->alias([
            'rol.activo' => App\Http\Middleware\EstablecerRolActivo::class,
        ]);

        // Inertia comparte el contexto de sesión (usuario, rol activo, permisos)
        // con todas las páginas Vue.
        $middleware->web(append: [
            App\Http\Middleware\HandleInertiaRequests::class,
        ]);

        /*
         * A dónde mandar a quien no ha iniciado sesión.
         *
         * Laravel busca por defecto una ruta llamada `login`; la nuestra es
         * `tenant.login` porque vive en el dominio de cada escuela. Sin esto,
         * entrar sin sesión a una página protegida reventaba con
         * "Route [login] not defined" (500) en vez de mostrar el acceso.
         */
        $middleware->redirectGuestsTo(function (Request $peticion) {
            return tenant() !== null ? route('tenant.login') : '/';
        });
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        /*
         * Errores presentables en lugar de pantallas en blanco.
         *
         * El caso que motivó esto: al enviar un formulario sin permiso, el
         * backend respondía 403 correctamente pero la interfaz NO mostraba
         * nada —el usuario creía que había guardado—. Ahora un 403 en una
         * acción de escritura regresa con un aviso, y en una navegación
         * muestra una página explicando qué pasó y cómo continuar.
         *
         * 403/404/419 son situaciones esperadas y se presentan siempre. El 500
         * se deja pasar en local para no ocultar el stack trace que hace falta
         * al depurar.
         */
        $exceptions->respond(function (Response $respuesta, \Throwable $excepcion, Request $peticion) {
            $estado = $respuesta->getStatusCode();

            if (! in_array($estado, [403, 404, 419, 500, 503], true)) {
                return $respuesta;
            }

            if ($estado === 500 && app()->environment('local')) {
                return $respuesta;
            }

            // En una escritura (POST/PUT/DELETE) el usuario está en una pantalla
            // útil: se le regresa ahí con el motivo, no a una página de error.
            if (! $peticion->isMethod('GET') && $peticion->header('X-Inertia')) {
                return back()->with('error', match ($estado) {
                    403 => 'No tienes permiso para realizar esa acción con tu rol activo.',
                    419 => 'Tu sesión expiró. Vuelve a intentarlo.',
                    default => 'No se pudo completar la operación.',
                });
            }

            return Inertia::render('Error', ['estado' => $estado])
                ->toResponse($peticion)
                ->setStatusCode($estado);
        });
    })->create();
