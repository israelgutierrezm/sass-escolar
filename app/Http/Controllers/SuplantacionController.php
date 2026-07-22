<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Identidad\Usuario;
use App\Services\Suplantador;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Entrar y salir de la vista de otra persona.
 *
 * Iniciar exige `suplantar-usuarios`; VOLVER no exige nada, porque mientras se
 * suplanta se tienen los permisos del suplantado y pedirle algo para salir
 * podría dejar a alguien atrapado en una identidad ajena.
 */
class SuplantacionController extends Controller
{
    public function __construct(private readonly Suplantador $suplantador) {}

    public function iniciar(Request $request, Usuario $usuario): RedirectResponse
    {
        try {
            $this->suplantador->iniciar($request, $usuario);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['suplantacion' => $e->getMessage()]);
        }

        // Al panel y no de vuelta: la pantalla anterior era administrativa y la
        // persona suplantada probablemente no puede verla.
        return redirect()
            ->route('tenant.dashboard')
            ->with('exito', "Estás viendo el sistema como {$usuario->usuario}.");
    }

    public function terminar(Request $request): RedirectResponse
    {
        $real = $this->suplantador->terminar($request);

        if ($real === null) {
            return redirect()->route('tenant.login');
        }

        return redirect()
            ->route('tenant.dashboard')
            ->with('exito', 'Volviste a tu cuenta.');
    }
}
