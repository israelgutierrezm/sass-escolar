<?php

declare(strict_types=1);

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Acceso al panel de la CASA (landlord). Autentica super admins contra la BD
 * central con el guard `central`, aparte de la sesión de cualquier escuela.
 */
class AutenticacionCentralController extends Controller
{
    public function mostrarLogin(): View|RedirectResponse
    {
        // URLs relativas: las rutas centrales viven en dos dominios (127.0.0.1 y
        // localhost) con el mismo nombre, así que `route()` fijaría uno solo y
        // cruzaría de dominio. Relativo se queda en el host por el que entraron.
        if (Auth::guard('central')->check()) {
            return redirect('/escuelas');
        }

        return view('central.login');
    }

    public function acceso(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::guard('central')->attempt($datos, true)) {
            throw ValidationException::withMessages([
                'email' => 'Esas credenciales no coinciden.',
            ]);
        }

        $request->session()->regenerate();

        return redirect()->intended('/escuelas');
    }

    public function salir(Request $request): RedirectResponse
    {
        Auth::guard('central')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
