<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Models\Identidad\PersonaRol;
use App\Models\Identidad\Usuario;
use App\Services\BitacoraAccesos;
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
        return Inertia::render('Auth/Login');
    }

    public function login(LoginRequest $request, BitacoraAccesos $bitacora): RedirectResponse
    {
        $request->autenticar();
        $request->session()->regenerate();

        /** @var Usuario $usuario */
        $usuario = Auth::user();

        $this->asegurarRolActivo($usuario);

        $usuario->forceFill(['conectado' => true])->save();

        // Se asienta la ENTRADA con el equipo, navegador e IP desde donde entró.
        $bitacora->entrada($usuario, $request);

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

    /**
     * Al entrar, si el usuario no trae rol activo válido se le asigna el
     * primero disponible. Sin rol activo no podría ver nada.
     */
    private function asegurarRolActivo(Usuario $usuario): void
    {
        if ($usuario->rol_activo_id !== null && $usuario->puedeUsarRol($usuario->rol_activo_id)) {
            return;
        }

        $primerRol = PersonaRol::query()
            ->where('persona_id', $usuario->persona_id)
            ->where('activo', true)
            ->value('rol_id');

        $usuario->forceFill(['rol_activo_id' => $primerRol])->save();
    }
}
