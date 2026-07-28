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
        // Solo puede haber una: se pasa como objeto único (o null), no como lista.
        $institucion = Institucion::query()->withCount('campus')->first();

        return Inertia::render('Academico/Instituciones/Index', [
            'institucion' => $institucion === null ? null : [
                'id' => $institucion->id,
                'clave' => $institucion->clave,
                'nombre' => $institucion->nombre,
                'logo' => $institucion->urlLogo(),
                'campus_count' => $institucion->campus_count,
            ],
            'puedeEditar' => $request->user()->can('editar-catalogo-academico'),
        ]);
    }

    public function create(): Response|RedirectResponse
    {
        // Solo puede haber UNA. Si ya existe, se manda a editarla en vez de
        // ofrecer un alta que se rechazaría.
        $existente = Institucion::query()->first();

        if ($existente !== null) {
            return redirect()->route('tenant.academico.instituciones.edit', $existente);
        }

        return Inertia::render('Academico/Instituciones/Formulario', ['institucion' => null]);
    }

    public function store(Request $request): RedirectResponse
    {
        // Una sola institución por escuela. El formulario ya oculta el botón,
        // pero un POST se arma a mano: aquí se rechaza de todos modos.
        if (Institucion::query()->exists()) {
            return redirect()->route('tenant.academico.instituciones.index')
                ->with('error', 'Ya existe una institución. Solo puedes editarla.');
        }

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

    // Una institución NO se elimina: es la escuela misma. Solo se edita. Por eso
    // no hay `destroy` ni ruta de borrado.

    /** Sirve el logo en línea (no forza descarga) para que el <img> lo pinte. */
    public function logo(Institucion $institucion): StreamedResponse
    {
        abort_if($institucion->logo_url === null, 404);
        abort_unless(Storage::disk('local')->exists($institucion->logo_url), 404);

        return Storage::disk('local')->response($institucion->logo_url);
    }

    /**
     * El logo de la ÚNICA institución, sin autenticación: lo usan el login y la
     * barra lateral. Como solo puede haber una, no lleva id en la URL.
     */
    public function logoPublico(): StreamedResponse
    {
        $institucion = Institucion::query()->whereNotNull('logo_url')->first();

        abort_if($institucion === null, 404);
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
