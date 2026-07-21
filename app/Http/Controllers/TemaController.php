<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Identidad\Tema;
use App\Models\Identidad\Usuario;
use App\Models\Identidad\UsuarioTemaOverride;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Preferencias visuales del usuario.
 *
 * Cambiar de tema o ajustar un color es una preferencia personal: no requiere
 * permiso especial, cualquiera puede hacerlo sobre su propia cuenta.
 */
class TemaController extends Controller
{
    public function actualizar(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'tema_id' => ['required', 'integer', Rule::exists('temas', 'id')->whereNull('deleted_at')],
        ], [], ['tema_id' => 'tema']);

        /** @var Usuario $usuario */
        $usuario = Auth::user();

        $usuario->forceFill(['tema_id' => $datos['tema_id']])->save();

        // Los ajustes personales son del tema anterior: al cambiar de tema se
        // descartan, o el usuario arrastraría colores que no pegan con el nuevo.
        UsuarioTemaOverride::query()->where('usuario_id', $usuario->id)->forceDelete();

        return back()->with('exito', 'Tema actualizado.');
    }

    /**
     * Guarda un ajuste personal de color sobre el tema actual. Un valor vacío
     * lo elimina y devuelve el token a su valor del tema.
     */
    public function personalizar(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'token' => ['required', 'string', 'max:60'],
            'valor' => ['nullable', 'string', 'max:40'],
        ]);

        /** @var Usuario $usuario */
        $usuario = Auth::user();

        $tema = $usuario->tema_id !== null
            ? Tema::find($usuario->tema_id)
            : Tema::query()->where('es_default', true)->first();

        if ($tema === null || ! $tema->permite_override_usuario) {
            return back()->with('error', 'Este tema no admite personalización.');
        }

        if (blank($datos['valor'])) {
            UsuarioTemaOverride::query()
                ->where('usuario_id', $usuario->id)
                ->where('token', $datos['token'])
                ->forceDelete();

            return back()->with('exito', 'Color restablecido.');
        }

        UsuarioTemaOverride::query()->updateOrCreate(
            ['usuario_id' => $usuario->id, 'token' => $datos['token']],
            ['valor' => $datos['valor']],
        );

        return back()->with('exito', 'Color actualizado.');
    }

    /** Descarta todos los ajustes personales y vuelve al tema tal cual. */
    public function restablecer(): RedirectResponse
    {
        /** @var Usuario $usuario */
        $usuario = Auth::user();

        UsuarioTemaOverride::query()->where('usuario_id', $usuario->id)->forceDelete();

        return back()->with('exito', 'Personalización restablecida.');
    }
}
