<?php

declare(strict_types=1);

namespace App\Http\Controllers\Emision;

use App\Http\Controllers\Controller;
use App\Models\Emision\Responsable;
use App\Models\Emision\TituloProfesional;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Catálogo de títulos profesionales (Ing., Lic., Dr., …). Vive dentro de
 * Configuración → Catálogos y lo comparten certificación y titulación (es la
 * misma tabla); por eso la sección solo cambia el encabezado y las rutas.
 */
class TituloProfesionalController extends Controller
{
    public function index(Request $request): Response
    {
        $seccion = (string) $request->route('seccion');

        return Inertia::render('Emision/Catalogos', [
            'seccion' => $seccion,
            'tituloSeccion' => $seccion === 'titulacion' ? 'Titulación electrónica' : 'Certificación electrónica',
            'titulos' => TituloProfesional::query()->orderBy('abreviatura')->get(['id', 'abreviatura', 'descripcion'])
                ->map(fn (TituloProfesional $t) => [
                    'id' => $t->id,
                    'abreviatura' => $t->abreviatura,
                    'descripcion' => $t->descripcion,
                    'en_uso' => Responsable::query()->where('titulo_profesional_id', $t->id)->exists(),
                ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        TituloProfesional::create($this->validar($request));

        return back()->with('exito', 'Título agregado.');
    }

    public function update(Request $request, TituloProfesional $titulo): RedirectResponse
    {
        $titulo->update($this->validar($request, $titulo->id));

        return back()->with('exito', 'Título actualizado.');
    }

    public function destroy(TituloProfesional $titulo): RedirectResponse
    {
        if (Responsable::query()->where('titulo_profesional_id', $titulo->id)->exists()) {
            return back()->with('error', 'No se puede eliminar: hay responsables que usan este título.');
        }

        $titulo->delete();

        return back()->with('exito', 'Título eliminado.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validar(Request $request, ?int $id = null): array
    {
        return $request->validate([
            'abreviatura' => ['required', 'string', 'max:20', Rule::unique('titulos_profesionales', 'abreviatura')->ignore($id)->whereNull('deleted_at')],
            'descripcion' => ['required', 'string', 'max:150'],
        ]);
    }
}
