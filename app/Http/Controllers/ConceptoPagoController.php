<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Finanzas\Adeudo;
use App\Models\Finanzas\ConceptoPago;
use App\Models\Finanzas\ConceptoPlan;
use App\Support\CatalogosSat;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Catálogo de conceptos de pago (colegiatura, inscripción, credencial…) con sus
 * datos fiscales para facturar: clave del SAT, unidad, objeto de impuesto y si
 * causa IVA.
 *
 * Se administra aparte de los planes de cobro porque un concepto se reutiliza
 * entre planes: la colegiatura es la misma partida fiscal la cobre quien la
 * cobre.
 */
class ConceptoPagoController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Finanzas/Conceptos/Index', [
            'conceptos' => ConceptoPago::query()
                ->orderBy('nombre')
                // `lineasDePlan` se llamaba `reglas` antes del rediseño del
                // motor de cobro; el nombre viejo quedó aquí y esta pantalla
                // reventaba con un 500 al abrirse.
                ->withCount(['adeudos', 'lineasDePlan'])
                ->get()
                ->map(fn (ConceptoPago $c) => [
                    'id' => $c->id,
                    'clave' => $c->clave,
                    'nombre' => $c->nombre,
                    'clave_sat' => $c->clave_sat,
                    'clave_unidad_sat' => $c->clave_unidad_sat,
                    'objeto_impuesto' => $c->objeto_impuesto,
                    'gravado' => $c->gravado,
                    'tasa_iva' => $c->tasa_iva,
                    'cuenta_contable' => $c->cuenta_contable,
                    'en_uso' => ($c->adeudos_count + $c->lineas_de_plan_count) > 0,
                ]),
            'catalogos' => ['objeto_impuesto' => CatalogosSat::objetoImpuesto()],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        ConceptoPago::create($this->validar($request));

        return back()->with('exito', 'Concepto de pago creado.');
    }

    public function update(Request $request, ConceptoPago $concepto): RedirectResponse
    {
        $concepto->update($this->validar($request, $concepto));

        return back()->with('exito', 'Concepto de pago actualizado.');
    }

    /**
     * Un concepto que ya generó adeudos o que alimenta una regla de cobro NO se
     * borra: sus adeudos y facturas seguirían apuntando a algo que desapareció.
     */
    public function destroy(ConceptoPago $concepto): RedirectResponse
    {
        $enUso = Adeudo::query()->where('concepto_id', $concepto->id)->exists()
            || ConceptoPlan::query()->where('concepto_id', $concepto->id)->exists();

        if ($enUso) {
            return back()->with('error', 'Este concepto ya se usa en cobros o reglas: no se elimina.');
        }

        $concepto->delete();

        return back()->with('exito', 'Concepto eliminado.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validar(Request $request, ?ConceptoPago $concepto = null): array
    {
        return $request->validate([
            'clave' => ['required', 'string', 'max:50', Rule::unique('conceptos_pago', 'clave')->ignore($concepto?->id)],
            'nombre' => ['required', 'string', 'max:150'],
            'clave_sat' => ['nullable', 'string', 'max:15'],
            'clave_unidad_sat' => ['nullable', 'string', 'max:10'],
            'objeto_impuesto' => ['required', 'string', 'max:2'],
            'gravado' => ['boolean'],
            // Solo aplica cuando causa IVA; 0.16 = 16%, 0 = tasa cero.
            'tasa_iva' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'cuenta_contable' => ['nullable', 'string', 'max:50'],
        ]);
    }
}
