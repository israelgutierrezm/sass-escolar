<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Models\Identidad\Usuario;
use App\Services\BitacoraAccesos;
use App\Services\IniciadorSesion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Acceso a una escuela (tenant).
 *
 * El login es de PERSONAS con cualquier rol activo: un aspirante entra igual
 * que un docente o un administrativo; lo que cambia es su rol activo.
 */
class AutenticacionController extends Controller
{
    public function mostrarLogin(): Response
    {
        return Inertia::render('Auth/Login', [
            // Solo se ofrece «Continuar con Google» si el SSO está habilitado.
            'googleSso' => config('services.google.modo') !== 'off',
        ]);
    }

    public function login(LoginRequest $request, IniciadorSesion $iniciador): RedirectResponse
    {
        $request->autenticar();
        $request->session()->regenerate();

        /** @var Usuario $usuario */
        $usuario = Auth::user();

        $iniciador->finalizar($usuario, $request);

        return redirect()->intended(route('tenant.dashboard'));
    }

    public function logout(Request $request, BitacoraAccesos $bitacora): RedirectResponse
    {
        /** @var Usuario|null $usuario */
        $usuario = Auth::user();

        $usuario?->forceFill(['conectado' => false])->save();

        // La SALIDA se asienta antes de cerrar la sesión, mientras aún hay usuario.
        if ($usuario !== null) {
            $bitacora->salida($usuario, $request);
        }

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('tenant.login');
    }
}
