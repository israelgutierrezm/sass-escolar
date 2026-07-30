<?php

declare(strict_types=1);

namespace App\Http\Controllers\Emision;

use App\Http\Controllers\Controller;
use App\Models\Emision\TipoCertificacion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Catálogo de tipos de certificación (79 = Total, 80 = Parcial) que alimenta el
 * atributo idTipoCertificacion del DEC. Los dos oficiales van `protegido`: se
 * ven pero no se editan ni se borran. Vive en Certificación → Configuración.
 */
class TipoCertificacionController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Certificacion/Catalogos/Index', [
            'tipos' => TipoCertificacion::query()->orderBy('clave')->get(['id', 'clave', 'identificador', 'nombre', 'protegido']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        TipoCertificacion::create($this->validar($request));

        return back()->with('exito', 'Tipo de certificación agregado.');
    }

    public function update(Request $request, TipoCertificacion $tipo): RedirectResponse
    {
        if ($tipo->protegido) {
            return back()->with('error', 'Este tipo de certificación es oficial y no se puede modificar.');
        }

        $tipo->update($this->validar($request, $tipo->id));

        return back()->with('exito', 'Tipo de certificación actualizado.');
    }

    public function destroy(TipoCertificacion $tipo): RedirectResponse
    {
        if ($tipo->protegido) {
            return back()->with('error', 'Este tipo de certificación es oficial y no se puede eliminar.');
        }

        $tipo->delete();

        return back()->with('exito', 'Tipo de certificación eliminado.');
    }

    /** @return array<string, mixed> */
    private function validar(Request $request, ?int $id = null): array
    {
        return $request->validate([
            'clave' => ['required', 'string', 'max:50', Rule::unique('tipos_certificacion', 'clave')->ignore($id)->whereNull('deleted_at')],
            'identificador' => ['nullable', 'string', 'max:40'],
            'nombre' => ['required', 'string', 'max:255'],
        ], [
            'clave.unique' => 'Ya existe un tipo de certificación con esa clave.',
        ]);
    }
}
