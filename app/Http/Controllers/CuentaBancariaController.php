<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\AvisoParaElUsuario;
use App\Models\Academico\Carrera;
use App\Models\Finanzas\ComprobantePago;
use App\Models\Finanzas\CuentaBancaria;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Las cuentas de la escuela para recibir transferencias, y a qué carreras
 * aplica cada una.
 *
 * ── Sin carreras marcadas vale para todas ──────────────────────────────────
 * Es el caso simple —una escuela, una cuenta— y el más común, así que se
 * resuelve no marcando nada en vez de obligando a seleccionar la lista entera
 * cada vez que se abre una carrera nueva.
 */
class CuentaBancariaController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('Finanzas/CuentasBancarias', [
            'cuentas' => CuentaBancaria::query()
                ->with('carreras:id,nombre')
                ->orderBy('nombre')
                ->get()
                ->map(fn (CuentaBancaria $c) => [
                    'id' => $c->id,
                    'nombre' => $c->nombre,
                    'banco' => $c->banco,
                    'titular' => $c->titular,
                    'clabe' => $c->clabe,
                    'numero_cuenta' => $c->numero_cuenta,
                    'instrucciones' => $c->instrucciones,
                    'activa' => $c->activa,
                    'carreras' => $c->carreras->pluck('id'),
                    // Vacío = todas: se dice con palabras, no con una lista larga.
                    'alcance' => $c->carreras->isEmpty()
                        ? 'Todas las carreras'
                        : $c->carreras->pluck('nombre')->implode(', '),
                ]),
            'carreras' => Carrera::query()->orderBy('nombre')->get(['id', 'nombre']),
            'puedeEditar' => $request->user()->can('gestionar-planes-cobro'),
        ]);
    }

    public function guardar(Request $request, ?CuentaBancaria $cuenta = null): RedirectResponse
    {
        $datos = $this->validar($request);

        $cuenta ??= new CuentaBancaria;
        $cuenta->fill($datos)->save();

        // Vacío = todas las carreras; `sync` con lista vacía es justo eso.
        $cuenta->carreras()->sync($request->input('carreras', []));

        return back()->with('exito', 'Cuenta bancaria guardada.');
    }

    public function eliminar(CuentaBancaria $cuenta): RedirectResponse
    {
        /*
         * No se borra una cuenta con comprobantes: son el respaldo de pagos ya
         * cobrados, y quedarían apuntando a un hueco justo cuando alguien
         * pregunte de dónde salió ese dinero. Se desactiva, que deja de
         * ofrecerse sin borrar la historia.
         */
        AvisoParaElUsuario::si(
            ComprobantePago::where('cuenta_bancaria_id', $cuenta->id)->exists(),
            422,
            'Esa cuenta ya tiene comprobantes asociados. Desactívala en vez de borrarla: '
                .'los comprobantes son el respaldo de pagos ya registrados.',
        );

        $cuenta->delete();

        return back()->with('exito', 'Cuenta bancaria eliminada.');
    }

    /** @return array<string, mixed> */
    private function validar(Request $request): array
    {
        $datos = $request->validate([
            'nombre' => ['required', 'string', 'max:120'],
            'banco' => ['required', 'string', 'max:80'],
            'titular' => ['required', 'string', 'max:160'],
            // La CLABE mexicana son 18 dígitos, ni uno más ni uno menos.
            'clabe' => ['nullable', 'string', 'digits:18'],
            'numero_cuenta' => ['nullable', 'string', 'max:30'],
            'instrucciones' => ['nullable', 'string', 'max:1000'],
            'activa' => ['boolean'],
            'carreras' => ['array'],
            'carreras.*' => ['integer'],
        ], [
            'clabe.digits' => 'La CLABE tiene exactamente 18 dígitos.',
        ]);

        /*
         * Sin CLABE ni número de cuenta no hay a dónde transferir: lo que
         * vería quien va a pagar es un banco y un nombre. Se ataja aquí porque
         * el error sale con el alumno delante, no al guardar.
         */
        AvisoParaElUsuario::si(
            blank($datos['clabe'] ?? null) && blank($datos['numero_cuenta'] ?? null),
            422,
            'Pon al menos la CLABE o el número de cuenta: sin eso no hay a dónde transferir.',
        );

        return collect($datos)->except('carreras')->all();
    }
}
