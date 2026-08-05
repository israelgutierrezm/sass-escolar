<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Finanzas\ConceptoPago;
use App\Models\Finanzas\Descuento;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Descuentos comerciales: lo contrario al recargo.
 *
 * No se le otorgan a nadie —eso son las becas—: se evalúan al calcular el cargo
 * o al momento del pago. Un descuento por PAGO ANTICIPADO premia pagar N días
 * antes del límite; uno de CAMPAÑA vive en una ventana de fechas.
 */
class DescuentoController extends Controller
{
    public function index(Request $request): Response
    {
        // Un descuento se busca por su nombre y se acota por su tipo o por si
        // sigue vigente: es lo que se pregunta cuando alguien quiere saber «qué
        // le puedo aplicar a este alumno hoy».
        $filtros = [
            'busqueda' => trim((string) $request->query('busqueda', '')),
            'tipo' => $request->query('tipo'),
            'activo' => $request->query('activo'),
        ];

        $descuentos = Descuento::query()
            ->with('conceptos:id,nombre')
            ->when($filtros['busqueda'] !== '', fn ($q) => $q->where(fn ($sub) => $sub
                ->where('clave', 'like', "%{$filtros['busqueda']}%")
                ->orWhere('nombre', 'like', "%{$filtros['busqueda']}%")))
            ->when($filtros['tipo'], fn ($q, $tipo) => $q->where('tipo', $tipo))
            ->when($filtros['activo'], fn ($q) => $q->where('activo', true))
            ->orderBy('nombre')
            ->get()
            ->map(fn (Descuento $d) => [
                'id' => $d->id,
                'clave' => $d->clave,
                'nombre' => $d->nombre,
                'descripcion' => $d->descripcion,
                'tipo' => $d->tipo,
                'modo' => $d->modo,
                'valor' => (float) $d->valor,
                'tope_monto' => $d->tope_monto !== null ? (float) $d->tope_monto : null,
                'dias_anticipacion' => $d->dias_anticipacion,
                'vigente_desde' => $d->vigente_desde?->toDateString(),
                'vigente_hasta' => $d->vigente_hasta?->toDateString(),
                'conceptos' => $d->conceptos->pluck('nombre')->all(),
                'activo' => $d->activo,
            ]);

        return Inertia::render('Finanzas/Descuentos/Index', [
            'filtros' => $filtros,
            'descuentos' => $descuentos,
            'catalogoConceptos' => ConceptoPago::orderBy('nombre')->get(['id', 'nombre']),
            'tipos' => [
                ['valor' => Descuento::TIPO_PAGO_ANTICIPADO, 'etiqueta' => 'Por pago anticipado'],
                ['valor' => Descuento::TIPO_CAMPANA, 'etiqueta' => 'Campaña por tiempo limitado'],
                ['valor' => Descuento::TIPO_MANUAL, 'etiqueta' => 'Manual'],
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $datos = $this->validar($request);

        $descuento = Descuento::create($datos);
        $descuento->conceptos()->sync($datos['conceptos'] ?? []);

        return back()->with('exito', 'Descuento creado.');
    }

    public function update(Request $request, Descuento $descuento): RedirectResponse
    {
        $datos = $this->validar($request, $descuento);

        $descuento->update($datos);
        $descuento->conceptos()->sync($datos['conceptos'] ?? []);

        return back()->with('exito', 'Descuento actualizado.');
    }

    public function destroy(Descuento $descuento): RedirectResponse
    {
        $descuento->delete();

        return back()->with('exito', 'Descuento eliminado.');
    }

    /** @return array<string, mixed> */
    private function validar(Request $request, ?Descuento $descuento = null): array
    {
        return $request->validate([
            'clave' => ['required', 'string', 'max:50', Rule::unique('descuentos', 'clave')->ignore($descuento?->id)],
            'nombre' => ['required', 'string', 'max:150'],
            'descripcion' => ['nullable', 'string', 'max:255'],
            'tipo' => ['required', Rule::in([Descuento::TIPO_PAGO_ANTICIPADO, Descuento::TIPO_CAMPANA, Descuento::TIPO_MANUAL])],
            'modo' => ['required', Rule::in([Descuento::MODO_PORCENTAJE, Descuento::MODO_MONTO_FIJO])],
            'valor' => ['required', 'numeric', 'min:0'],
            'tope_monto' => ['nullable', 'numeric', 'min:0'],
            // Solo tiene sentido en pago anticipado; el motor lo exige ahí.
            'dias_anticipacion' => ['nullable', 'required_if:tipo,'.Descuento::TIPO_PAGO_ANTICIPADO, 'integer', 'min:1', 'max:365'],
            'vigente_desde' => ['nullable', 'date'],
            'vigente_hasta' => ['nullable', 'date', 'after_or_equal:vigente_desde'],
            'conceptos' => ['array'],
            'conceptos.*' => ['integer', Rule::exists('conceptos_pago', 'id')],
            'activo' => ['boolean'],
        ], [
            'dias_anticipacion.required_if' => 'Un descuento por pago anticipado necesita saber cuántos días antes hay que pagar.',
        ]);
    }
}
