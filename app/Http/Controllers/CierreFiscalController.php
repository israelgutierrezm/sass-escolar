<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\CierreFiscal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Cerrar el mes fiscal.
 *
 * Va con permiso propio —`cerrar-periodo-fiscal`— y no con `facturar`: declarar
 * cerrado un mes es un acto de supervisión, y quien emite CFDI todos los días
 * no tiene por qué poder hacerlo. Mismo criterio que separó `gestionar-emisores`
 * de emitir.
 *
 * NO se acota por campus, a diferencia del listado de facturas: un periodo
 * fiscal es de la persona moral, no de un plantel. Cerrar «marzo del campus
 * norte» no significa nada ante el SAT.
 */
class CierreFiscalController extends Controller
{
    public function __construct(private readonly CierreFiscal $cierre) {}

    public function index(): Response
    {
        return Inertia::render('Finanzas/CierreFiscal', [
            'periodos' => $this->cierre->panorama(),
        ]);
    }

    public function cerrar(Request $peticion): RedirectResponse
    {
        $datos = $peticion->validate([
            'anio' => ['required', 'integer', 'min:2000', 'max:2100'],
            'mes' => ['required', 'integer', 'min:1', 'max:12'],
        ]);

        try {
            // Se convierte a propósito: `integer` ACEPTA la cadena «9» y la
            // devuelve como cadena, y el servicio la tipa. Es la trampa que este
            // proyecto ya se cobró en reportes y en el panorama documental.
            $periodo = $this->cierre->cerrar((int) $datos['anio'], (int) $datos['mes']);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('exito', $periodo->etiqueta().' quedó cerrado.');
    }

    public function reabrir(Request $peticion): RedirectResponse
    {
        $datos = $peticion->validate([
            'anio' => ['required', 'integer', 'min:2000', 'max:2100'],
            'mes' => ['required', 'integer', 'min:1', 'max:12'],
            // Obligatorio: reabrir un mes declarado habilita cambiar un número
            // que ya se presentó, y dentro de un año esto es lo único que
            // explica por qué se hizo.
            'motivo' => ['required', 'string', 'max:255'],
        ]);

        try {
            $periodo = $this->cierre->reabrir((int) $datos['anio'], (int) $datos['mes'], $datos['motivo']);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('advertencia', $periodo->etiqueta().' se reabrió. Vuelve a cerrarlo al terminar.');
    }
}
