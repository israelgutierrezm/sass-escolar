<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ControlEscolar\BibliotecaEnlace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * La biblioteca digital: lo que ve el alumno y lo que administra la escuela.
 *
 * Las dos caras viven en el mismo controlador porque comparten el catálogo y la
 * regla de qué se pinta como tarjeta y qué como enlace suelto. Separarlas
 * obligaría a repetir esa decisión en dos sitios, que es justo donde acaban
 * divergiendo.
 *
 * El permiso dice QUIÉN entra; el interruptor de la sección, si la escuela la
 * tiene abierta. Las rutas llevan los dos.
 */
class BibliotecaController extends Controller
{
    /** Lo que ve el alumno. */
    public function index(): Response
    {
        $enlaces = BibliotecaEnlace::query()->publicados()->get();

        return Inertia::render('Biblioteca/Index', [
            /*
             * Van separados desde el servidor y no mezclados con una bandera.
             *
             * En pantalla son dos bloques distintos —una cuadrícula de tarjetas
             * y una lista— y repartirlos en el navegador obligaría a recorrer la
             * colección dos veces en cada repintado para acabar en lo mismo.
             */
            'tarjetas' => $this->paraPantalla($enlaces->filter->esTarjeta()),
            'directos' => $this->paraPantalla($enlaces->reject->esTarjeta()),
        ]);
    }

    /** Lo que administra Control Escolar. */
    public function gestion(): Response
    {
        return Inertia::render('Escolar/Biblioteca', [
            'enlaces' => BibliotecaEnlace::query()
                ->orderBy('orden')
                ->orderBy('id')
                ->get(['id', 'titulo', 'descripcion', 'url', 'imagen_url', 'orden', 'activo']),
        ]);
    }

    public function store(Request $peticion): RedirectResponse
    {
        $datos = $this->validado($peticion);

        // Al final de la lista: quien publica algo nuevo no espera que se le
        // cuele en medio de lo que ya tenía ordenado.
        $datos['orden'] = (int) BibliotecaEnlace::query()->max('orden') + 1;

        BibliotecaEnlace::create($datos);

        return back()->with('success', 'El recurso quedó publicado.');
    }

    public function update(Request $peticion, BibliotecaEnlace $enlace): RedirectResponse
    {
        $enlace->update($this->validado($peticion));

        return back()->with('success', 'Se guardaron los cambios.');
    }

    public function destroy(BibliotecaEnlace $enlace): RedirectResponse
    {
        $enlace->delete();

        return back()->with('success', 'El recurso se quitó de la biblioteca.');
    }

    /**
     * Reacomoda la lista completa.
     *
     * Llega el orden entero y se reescribe, en vez de mandar «este subió un
     * puesto»: con dos personas acomodando a la vez, los movimientos relativos
     * se aplican sobre listas distintas y el resultado no es ninguno de los dos.
     * Mandar la lista final deja siempre exactamente lo que se vio en pantalla.
     */
    public function reordenar(Request $peticion): RedirectResponse
    {
        $datos = $peticion->validate([
            'ids' => ['present', 'array'],
            'ids.*' => ['integer', 'exists:biblioteca_enlaces,id'],
        ]);

        DB::transaction(function () use ($datos) {
            foreach (array_values($datos['ids']) as $orden => $id) {
                BibliotecaEnlace::query()->whereKey($id)->update(['orden' => $orden]);
            }
        });

        return back()->with('success', 'Se guardó el orden.');
    }

    /** @return array<string, mixed> */
    private function validado(Request $peticion): array
    {
        return $peticion->validate([
            'titulo' => ['required', 'string', 'max:150'],
            'descripcion' => ['nullable', 'string', 'max:300'],
            /*
             * Sólo http y https.
             *
             * Lo que se captura aquí lo publica la escuela a todos sus alumnos
             * como un enlace en el que se hace clic, así que el esquema importa.
             * Medido en esta versión de Laravel: la regla `url` a secas YA
             * rechaza `javascript:`, `data:`, `file:`, `mailto:` y `vbscript:`
             * —exige la forma `esquema://servidor`—, pero deja pasar `ftp://` y
             * `ws://`. Acotarla es lo que cierra esos dos.
             */
            'url' => ['required', 'string', 'max:2048', 'url:http,https'],
            'imagen_url' => ['nullable', 'string', 'max:2048'],
            'activo' => ['required', 'boolean'],
        ]);
    }

    /**
     * @param  Collection<int, BibliotecaEnlace>  $enlaces
     * @return array<int, array<string, mixed>>
     */
    private function paraPantalla($enlaces): array
    {
        return $enlaces
            ->map(fn (BibliotecaEnlace $e) => [
                'id' => $e->id,
                'titulo' => $e->titulo,
                'descripcion' => $e->descripcion,
                'url' => $e->url,
                'imagen_url' => $e->imagen_url,
            ])
            ->values()
            ->all();
    }
}
