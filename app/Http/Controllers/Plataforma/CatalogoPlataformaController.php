<?php

declare(strict_types=1);

namespace App\Http\Controllers\Plataforma;

use App\Http\Controllers\Controller;
use App\Models\Landlord\Genero;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Catálogos de la plataforma que NO son académicos: hoy el catálogo de género,
 * que alimenta el atributo idGenero del certificado electrónico (DEC). Vive en
 * Plataforma → Configuración → Catálogos.
 *
 * OJO: género es un catálogo LANDLORD (compartido por todas las escuelas). Se
 * administra aquí porque su valor oficial es nacional; editarlo afecta a todas.
 */
class CatalogoPlataformaController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Plataforma/Configuracion/Catalogos', [
            'generos' => Genero::query()->orderBy('id')->get(['id', 'clave', 'identificador', 'nombre']),
        ]);
    }

    public function storeGenero(Request $request): RedirectResponse
    {
        Genero::create($this->validar($request));

        return back()->with('exito', 'Género agregado.');
    }

    public function updateGenero(Request $request, int $genero): RedirectResponse
    {
        Genero::query()->findOrFail($genero)->update($this->validar($request, $genero));

        return back()->with('exito', 'Género actualizado.');
    }

    public function destroyGenero(int $genero): RedirectResponse
    {
        Genero::query()->findOrFail($genero)->delete();

        return back()->with('exito', 'Género eliminado.');
    }

    /** @return array<string, mixed> */
    private function validar(Request $request, ?int $id = null): array
    {
        return $request->validate([
            'clave' => ['required', 'string', 'max:50', Rule::unique((new Genero)->getTable(), 'clave')->ignore($id)],
            'identificador' => ['nullable', 'string', 'max:40'],
            'nombre' => ['required', 'string', 'max:255'],
        ], [
            'clave.unique' => 'Ya existe un género con esa clave.',
        ]);
    }
}
