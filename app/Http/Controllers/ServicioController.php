<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Finanzas\ConceptoPago;
use App\Models\Finanzas\Servicio;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * El catálogo de productos y servicios: lo que la escuela vende suelto.
 *
 * Aquí se define el precio y el concepto fiscal. Qué puede pedir el alumno se
 * decide en Control Escolar, sobre estas mismas filas: son dos áreas mirando el
 * mismo servicio, y por eso ninguna de las dos escribe los campos de la otra
 * —ver `validado`, que deja fuera `solicitable` e `instrucciones`.
 */
class ServicioController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Finanzas/Servicios', [
            'servicios' => Servicio::query()
                ->with('concepto:id,nombre')
                ->orderBy('nombre')
                ->get()
                ->map(fn (Servicio $s) => [
                    'id' => $s->id,
                    'clave' => $s->clave,
                    'nombre' => $s->nombre,
                    'descripcion' => $s->descripcion,
                    'concepto_id' => $s->concepto_id,
                    'concepto' => $s->concepto?->nombre,
                    'precio' => (float) $s->precio,
                    'tiene_costo' => $s->tieneCosto(),
                    'solicitable' => $s->solicitable,
                    'activo' => $s->activo,
                ]),

            'conceptos' => ConceptoPago::query()
                ->orderBy('nombre')
                ->get(['id', 'nombre'])
                ->all(),
        ]);
    }

    public function store(Request $peticion): RedirectResponse
    {
        Servicio::create($this->validado($peticion));

        return back()->with('success', 'El servicio quedó en el catálogo.');
    }

    public function update(Request $peticion, Servicio $servicio): RedirectResponse
    {
        $servicio->update($this->validado($peticion, $servicio));

        return back()->with('success', 'Se guardaron los cambios.');
    }

    /**
     * Retirar un servicio es apagarlo, no borrarlo.
     *
     * Un servicio que ya se cobró tiene adeudos y facturas colgando de su
     * concepto, y su nombre es lo que explica de qué era ese cargo. Borrarlo
     * dejaría estados de cuenta con renglones que no se pueden leer.
     */
    public function destroy(Servicio $servicio): RedirectResponse
    {
        $servicio->update(['activo' => false, 'solicitable' => false]);

        return back()->with('success', 'El servicio quedó fuera del catálogo.');
    }

    /**
     * Sólo lo que le toca a Finanzas.
     *
     * `solicitable` e `instrucciones` no aparecen a propósito: los administra
     * Control Escolar. Si esta pantalla los mandara —aunque fuera con el valor
     * que ya tenían—, cualquier guardado desde Finanzas pisaría lo que la otra
     * área acabara de configurar, y nadie relacionaría una cosa con la otra.
     *
     * @return array<string, mixed>
     */
    private function validado(Request $peticion, ?Servicio $servicio = null): array
    {
        $datos = $peticion->validate([
            'clave' => [
                'required', 'string', 'max:50',
                Rule::unique('servicios', 'clave')->ignore($servicio?->id)->whereNull('deleted_at'),
            ],
            'nombre' => ['required', 'string', 'max:150'],
            'descripcion' => ['nullable', 'string', 'max:500'],
            'concepto_id' => ['nullable', 'integer', 'exists:conceptos_pago,id'],
            'precio' => ['required', 'numeric', 'min:0', 'max:9999999.99'],
            'activo' => ['required', 'boolean'],
        ]);

        /*
         * Con precio, el concepto es obligatorio.
         *
         * No es burocracia: el concepto es lo que lleva la clave del SAT y la
         * tasa de IVA, y sin él el cargo que genere este servicio llegaría al
         * momento de facturar sin con qué timbrarse. El error saldría semanas
         * después, en la factura de alguien que ya pagó.
         */
        if ((float) $datos['precio'] > 0 && ($datos['concepto_id'] ?? null) === null) {
            throw ValidationException::withMessages([
                'concepto_id' => 'Un servicio con costo necesita su concepto de pago: de ahí salen la clave del SAT y el IVA con los que se factura.',
            ]);
        }

        return $datos;
    }
}
