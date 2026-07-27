<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Identidad\BitacoraAcceso;
use App\Models\Identidad\Usuario;
use App\Services\BitacoraAccesos;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rules\Password as ReglaPassword;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Recuperación de contraseña por correo.
 *
 * Usa el broker estándar de Laravel sobre la tabla `password_reset_tokens` del
 * tenant. Nunca revela si un correo está registrado —siempre responde igual—
 * para no volver la pantalla un oráculo de qué cuentas existen. Cada solicitud
 * y cada restablecimiento quedan en la bitácora de accesos.
 */
class RecuperacionController extends Controller
{
    public function solicitar(): Response
    {
        return Inertia::render('Auth/Recuperar');
    }

    public function enviarEnlace(Request $request, BitacoraAccesos $bitacora): RedirectResponse
    {
        $datos = $request->validate(['email' => ['required', 'email']]);

        $status = Password::sendResetLink(['email' => $datos['email']]);

        $usuario = Usuario::query()->where('email', $datos['email'])->first();
        $bitacora->registrar(
            BitacoraAcceso::RECUPERACION_SOLICITADA,
            $request,
            $usuario,
            $usuario?->persona_id,
            ['email' => $datos['email'], 'resultado' => $status],
        );

        // Misma respuesta exista o no la cuenta: no se filtra qué correos hay.
        return back()->with('exito', 'Si el correo está registrado, te enviamos un enlace para restablecer tu contraseña.');
    }

    public function restablecer(Request $request, string $token): Response
    {
        return Inertia::render('Auth/Restablecer', [
            'token' => $token,
            'email' => (string) $request->query('email', ''),
        ]);
    }

    public function actualizar(Request $request, BitacoraAccesos $bitacora): RedirectResponse
    {
        $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', ReglaPassword::min(8)],
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (Usuario $usuario, string $password) {
                // Restablecer TAMBIÉN habilita el acceso: una cuenta de censo
                // que recupera su contraseña deja de estar «sin acceso».
                $usuario->forceFill([
                    'password' => Hash::make($password),
                    'acceso_configurado' => true,
                ])->save();
            },
        );

        if ($status === Password::PasswordReset) {
            $usuario = Usuario::query()->where('email', $request->input('email'))->first();
            $bitacora->registrar(BitacoraAcceso::RECUPERACION_COMPLETADA, $request, $usuario, $usuario?->persona_id);

            return redirect()->route('tenant.login')->with('exito', 'Tu contraseña se actualizó. Ya puedes entrar.');
        }

        throw ValidationException::withMessages(['email' => [__($status)]]);
    }
}
