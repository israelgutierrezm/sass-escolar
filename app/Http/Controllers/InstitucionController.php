<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Academico\Institucion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Instituciones: la persona moral educativa dueña de los campus.
 *
 * Es un dato de encabezado —clave, nombre, logo— con el que se membreta lo que
 * la escuela emite. No condiciona ninguna otra lógica: un campus la referencia
 * de forma puramente informativa.
 *
 * El logo se maneja como la foto de una persona: se guarda en el disco privado
 * y se sirve por una ruta autenticada, no como archivo público. Un logo no es
 * secreto, pero abrir el disco al mundo por un caso que no lo exige es abrir de
 * más.
 */
class InstitucionController extends Controller
{
    private const CARPETA = 'instituciones';

    public function index(Request $request): Response
    {
        return Inertia::render('Academico/Instituciones/Index', [
            'instituciones' => Institucion::query()
                ->withCount('campus')
                ->orderBy('nombre')
                ->get()
                ->map(fn (Institucion $i) => [
                    'id' => $i->id,
                    'clave' => $i->clave,
                    'nombre' => $i->nombre,
                    'logo' => $i->urlLogo(),
                    'campus_count' => $i->campus_count,
                ]),
            'puedeEditar' => $request->user()->can('editar-catalogo-academico'),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Academico/Instituciones/Formulario', ['institucion' => null]);
    }

    public function store(Request $request): RedirectResponse
    {
        $institucion = Institucion::create($this->validar($request));

        $this->guardarLogo($request, $institucion);

        return redirect()->route('tenant.academico.instituciones.index')->with('exito', 'Institución creada.');
    }

    public function edit(Institucion $institucion): Response
    {
        return Inertia::render('Academico/Instituciones/Formulario', [
            'institucion' => [
                'id' => $institucion->id,
                'clave' => $institucion->clave,
                'nombre' => $institucion->nombre,
                'logo' => $institucion->urlLogo(),
            ],
        ]);
    }

    public function update(Request $request, Institucion $institucion): RedirectResponse
    {
        $institucion->update($this->validar($request, $institucion->id));

        $this->guardarLogo($request, $institucion);

        return redirect()->route('tenant.academico.instituciones.index')->with('exito', 'Institución actualizada.');
    }

    /**
     * No se elimina una institución con campus: se perdería a qué persona moral
     * pertenecen. Primero se reasignan o se cierran esos campus.
     */
    public function destroy(Institucion $institucion): RedirectResponse
    {
        if ($institucion->campus()->exists()) {
            return back()->with('error', 'No se puede eliminar: tiene campus asociados. Reasígnalos primero.');
        }

        if ($institucion->logo_url !== null) {
            Storage::disk('local')->delete($institucion->logo_url);
        }

        $institucion->delete();

        return back()->with('exito', 'Institución eliminada.');
    }

    /** Sirve el logo en línea (no forza descarga) para que el <img> lo pinte. */
    public function logo(Institucion $institucion): StreamedResponse
    {
        abort_if($institucion->logo_url === null, 404);
        abort_unless(Storage::disk('local')->exists($institucion->logo_url), 404);

        return Storage::disk('local')->response($institucion->logo_url);
    }

    /**
     * @return array<string, mixed>
     */
    private function validar(Request $request, ?int $id = null): array
    {
        return $request->validate([
            'clave' => ['required', 'string', 'max:50', Rule::unique('instituciones', 'clave')->ignore($id)->whereNull('deleted_at')],
            'nombre' => ['required', 'string', 'max:255'],
            'logo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp,svg', 'max:2048'],
        ], [
            'logo.image' => 'El logo debe ser una imagen.',
            'logo.max' => 'El logo no puede pasar de 2 MB.',
        ], [
            'clave' => 'clave',
        ]);
    }

    private function guardarLogo(Request $request, Institucion $institucion): void
    {
        if (! $request->hasFile('logo')) {
            return;
        }

        $anterior = $institucion->logo_url;

        $institucion->update(['logo_url' => $request->file('logo')->store(self::CARPETA, 'local')]);

        // La anterior se reemplaza y se borra: conservarla solo acumula
        // archivos que ya nadie referencia.
        if ($anterior !== null && $anterior !== $institucion->logo_url) {
            Storage::disk('local')->delete($anterior);
        }
    }
}
