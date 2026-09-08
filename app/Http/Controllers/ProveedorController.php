<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Finanzas\Proveedor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Compras y CxP, rebanada 1: el catálogo de PROVEEDORES.
 * Ver `docs/plan-compras-cxp.md`.
 *
 * Institucional, sin acotar por campus —un proveedor le factura a la persona
 * moral—. Se apaga, no se borra: sus egresos y cuentas por pagar son historia.
 */
class ProveedorController extends Controller
{
    public function index(Request $peticion): Response
    {
        $verInactivos = $peticion->boolean('inactivos');

        return Inertia::render('Finanzas/Proveedores', [
            'proveedores' => Proveedor::query()
                ->when(! $verInactivos, fn ($q) => $q->activos())
                ->orderBy('nombre')
                ->withCount('egresos')
                ->get()
                ->map(fn (Proveedor $p) => [
                    'id' => $p->id,
                    'nombre' => $p->nombre,
                    'rfc' => $p->rfc,
                    'razon_social' => $p->razon_social,
                    'contacto_nombre' => $p->contacto_nombre,
                    'telefono' => $p->telefono,
                    'correo' => $p->correo,
                    'domicilio' => $p->domicilio,
                    'notas' => $p->notas,
                    'activo' => $p->activo,
                    'egresos' => $p->egresos_count,
                ]),
            'filtros' => ['inactivos' => $verInactivos],
        ]);
    }

    public function guardar(Request $peticion, ?Proveedor $proveedor = null): RedirectResponse
    {
        $datos = $peticion->validate([
            'nombre' => ['required', 'string', 'max:200'],
            'rfc' => ['nullable', 'string', 'max:15', Rule::unique('proveedores', 'rfc')->ignore($proveedor?->id)],
            'razon_social' => ['nullable', 'string', 'max:200'],
            'contacto_nombre' => ['nullable', 'string', 'max:160'],
            'telefono' => ['nullable', 'string', 'max:40'],
            'correo' => ['nullable', 'email', 'max:160'],
            'domicilio' => ['nullable', 'string', 'max:255'],
            'notas' => ['nullable', 'string', 'max:500'],
        ], [
            'rfc.unique' => 'Ya hay un proveedor con ese RFC. El mismo capturado dos veces reparte sus egresos entre duplicados.',
        ]);

        $proveedor
            ? $proveedor->update($datos)
            : Proveedor::create($datos);

        return back(303)->with('exito', $proveedor ? 'Proveedor actualizado.' : 'Proveedor agregado.');
    }

    /** Apaga o reactiva. No se borra: sus egresos y CxP son historia. */
    public function alternar(Proveedor $proveedor): RedirectResponse
    {
        $proveedor->update(['activo' => ! $proveedor->activo]);

        return back(303)->with('exito', $proveedor->activo ? 'Proveedor reactivado.' : 'Proveedor desactivado.');
    }
}
