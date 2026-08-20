<?php

use App\Exceptions\AvisoParaElUsuario;
use App\Http\Middleware\EstablecerRolActivo;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\ModuloEncendido;
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
            'rol.activo' => EstablecerRolActivo::class,
            // Cierra las rutas de un módulo que la escuela tiene apagado. Es lo
            // que hace que apagar una sección no deje viva su dirección.
            'modulo' => ModuloEncendido::class,
        ]);

        // Inertia comparte el contexto de sesión (usuario, rol activo, permisos)
        // con todas las páginas Vue.
        $middleware->web(append: [
            HandleInertiaRequests::class,
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
        $exceptions->respond(function (Response $respuesta, Throwable $excepcion, Request $peticion) {
            $estado = $respuesta->getStatusCode();

            /*
             * El motivo, cuando quien lo escribió dijo que es para el usuario.
             *
             * La regla vive en la clase, no aquí: mostrar el mensaje de
             * CUALQUIER excepción sería una línea más corta y estaría mal —un
             * 403 lo lanzan también los Gates de Laravel y las librerías, en
             * inglés, describiendo su mecánica y a veces confirmando que existe
             * algo que quien pregunta no debería saber que existe—.
             */
            $motivo = AvisoParaElUsuario::motivoDe($excepcion);

            // El panel de la casa (dominios centrales) es Blade puro: sus errores
            // no se renderizan con la página Inertia de las escuelas.
            if (in_array($peticion->getHost(), config('tenancy.central_domains'), true)) {
                return $respuesta;
            }

            /*
             * El 422 entra SÓLO si el motivo es nuestro.
             *
             * Es el código correcto para «no se puede hacer eso ahora» —las tres
             * licencias de Zoom ocupadas a esa hora, por ejemplo— y sin esto la
             * respuesta salía como página HTML de error: el docente no veía nada
             * y la clase simplemente no aparecía.
             *
             * La condición importa. `ValidationException` también es 422, y a
             * ésa la maneja Inertia devolviendo los errores por campo; si se
             * dejara pasar por aquí, todos los formularios del sistema perderían
             * sus mensajes de validación y mostrarían uno genérico. `motivoDe`
             * devuelve null para todo lo que no sea `AvisoParaElUsuario`, así
             * que es justo la línea que las separa.
             */
            $esAvisoNuestro = $motivo !== null;

            if (! in_array($estado, [403, 404, 419, 500, 503], true)
                && ! ($estado === 422 && $esAvisoNuestro)) {
                return $respuesta;
            }

            if ($estado === 500 && app()->environment('local')) {
                return $respuesta;
            }

            // En una escritura (POST/PUT/DELETE) el usuario está en una pantalla
            // útil: se le regresa ahí con el motivo, no a una página de error.
            if (! $peticion->isMethod('GET') && $peticion->header('X-Inertia')) {
                return back()->with('error', $motivo ?? match ($estado) {
                    403 => 'No tienes permiso para realizar esa acción con tu rol activo.',
                    419 => 'Tu sesión expiró. Vuelve a intentarlo.',
                    default => 'No se pudo completar la operación.',
                });
            }

            return Inertia::render('Error', ['estado' => $estado, 'motivo' => $motivo])
                ->toResponse($peticion)
                ->setStatusCode($estado);
        });
    })->create();
