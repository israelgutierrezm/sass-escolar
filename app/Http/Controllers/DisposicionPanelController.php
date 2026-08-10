<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Identidad\Usuario;
use App\Panel\DisposicionDelPanel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Cómo acomoda cada quien su propio panel.
 *
 * **Sin permiso.** Todos los roles tienen panel y cada quien manda sobre el
 * suyo: no hay nada que autorizar porque no hay manera de tocar el de otro —el
 * usuario sale de la sesión, nunca de la petición—.
 *
 * Y tampoco hace falta comprobar que las claves que llegan sean tarjetas que
 * esta persona pueda ver: lo guardado se aplica al final, sobre la lista que el
 * permiso ya filtró, así que una clave que no le toca queda escrita pero no
 * encuentra pareja. Guardar basura sólo se hace daño a sí mismo.
 */
class DisposicionPanelController extends Controller
{
    public function __construct(private readonly DisposicionDelPanel $disposicion) {}

    public function update(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'tarjetas' => ['present', 'array', 'max:60'],
            'tarjetas.*.clave' => ['required', 'string', 'max:50'],
            // Los dos únicos tamaños. Que el ALTO no se pueda tocar no es una
            // omisión: una tarjeta mide lo que mide su contenido, y dejar
            // estirarla dejaría huecos en blanco dentro de la propia tarjeta.
            'tarjetas.*.ancho' => ['required', 'integer', Rule::in(DisposicionDelPanel::ANCHOS)],
        ]);

        $this->disposicion->guardar($this->quienPide(), $datos['tarjetas']);

        return back()->with('success', 'Se guardó cómo acomodaste tu panel.');
    }

    public function destroy(): RedirectResponse
    {
        $this->disposicion->olvidar($this->quienPide());

        return back()->with('success', 'Tu panel volvió al acomodo original.');
    }

    private function quienPide(): Usuario
    {
        /** @var Usuario $usuario */
        $usuario = Auth::user();

        return $usuario;
    }
}
