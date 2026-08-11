<?php

declare(strict_types=1);

/**
 * Armar una petición de formulario para invocar a un controlador desde un
 * script.
 *
 * ── Por qué hace falta ─────────────────────────────────────────────────────
 * Varios controladores dejaron de recibir `Illuminate\Http\Request` y ahora
 * piden su FormRequest —`AgregarMateriaRequest`, `GuardarAsignaturaRequest`—,
 * que es donde vive la validación. Las suites que les pasaban una `Request`
 * pelada reventaban con «Argument #1 must be of type ...Request, Request
 * given», o sea que no probaban nada desde el día del refactor.
 *
 * Un FormRequest no se construye con `new`: hay que derivarlo de una petición
 * real, darle el contenedor y el redirector, y disparar su validación a mano.
 * Cuatro pasos que se escribían mal cada vez; aquí van una sola vez.
 *
 * ── Y la validación se EJECUTA ─────────────────────────────────────────────
 * `validateResolved()` no es opcional: sin él la prueba llamaría al controlador
 * con datos que la pantalla de verdad habría rechazado, y pasaría comprobando
 * un camino que ningún usuario puede recorrer.
 *
 * Se incluye con `require` desde cada suite:
 *
 *     require __DIR__.'/apoyo-peticiones.php';
 */

use App\Models\Identidad\Usuario;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;

if (! function_exists('peticionDeFormulario')) {
    /**
     * @template T of FormRequest
     *
     * @param  class-string<T>  $clase  el FormRequest que el controlador exige
     * @param  array<string, mixed>  $datos
     * @param  array<string, mixed>  $parametrosDeRuta  los modelos que la ruta
     *                                                  ataría, p. ej. `['materia' => $materia]`
     * @return T
     */
    function peticionDeFormulario(
        string $clase,
        array $datos,
        ?Usuario $usuario = null,
        string $uri = '/',
        string $metodo = 'POST',
        array $parametrosDeRuta = [],
    ): FormRequest {
        $base = Request::create($uri, $metodo, $datos);

        /*
         * Los parámetros de la RUTA, cuando las reglas los consultan.
         *
         * `GuardarAsignaturaRequest` saca de `$this->route('materia')` a quién
         * debe IGNORAR la regla de clave única. Sin ruta atada, `route()`
         * devuelve null, la regla no ignora a nadie y editar una asignatura
         * choca contra su propia clave: «Ya existe una asignatura con esa
         * clave», sobre sí misma. Se ve como si la validación estuviera mal
         * cuando lo que falta es el contexto.
         */
        if ($parametrosDeRuta !== []) {
            $ruta = new Route([$metodo], $uri, []);
            $ruta->bind($base);

            foreach ($parametrosDeRuta as $nombre => $valor) {
                $ruta->setParameter($nombre, $valor);
            }

            $base->setRouteResolver(fn () => $ruta);
        }

        if ($usuario !== null) {
            $base->setUserResolver(fn () => $usuario);
        }

        // El contenedor tiene que ver ESTA petición: hay reglas que consultan
        // `request()` por dentro (unicidad que ignora al registro en edición,
        // por ejemplo) y con la petición vieja validarían contra otra cosa.
        app()->instance('request', $base);

        /** @var T $formulario */
        $formulario = $clase::createFrom($base);
        $formulario->setContainer(app())->setRedirector(app('redirect'));
        $formulario->setRouteResolver($base->getRouteResolver());

        if ($usuario !== null) {
            $formulario->setUserResolver(fn () => $usuario);
        }

        app()->instance('request', $formulario);

        $formulario->validateResolved();

        return $formulario;
    }
}
