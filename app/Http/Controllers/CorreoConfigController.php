<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Correo\CorreoConfig;
use App\Services\Correo\CorreoService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Configuración de correo (SMTP/Gmail) de la escuela.
 *
 * No envía directamente: para eso está `CorreoService`. Aquí se persiste la
 * config, se oculta la contraseña (solo se dice si existe) y se dispara la
 * prueba de envío.
 */
class CorreoConfigController extends Controller
{
    public function correo(): Response
    {
        $config = CorreoConfig::actual();

        return Inertia::render('Plataforma/Configuraciones/Correo', [
            'config' => [
                'activo' => $config->activo,
                'host' => $config->host,
                'puerto' => $config->puerto,
                'cifrado' => $config->cifrado,
                'usuario' => $config->usuario,
                'tiene_password' => filled($config->password),
                'remitente_correo' => $config->remitente_correo,
                'remitente_nombre' => $config->remitente_nombre,
                'prueba_estado' => $config->prueba_estado,
                'prueba_mensaje' => $config->prueba_mensaje,
                'prueba_en' => $config->prueba_en?->toDateTimeString(),
            ],
            'preset' => CorreoService::presetGmail(),
        ]);
    }

    public function guardar(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'activo' => ['boolean'],
            'host' => ['required', 'string', 'max:120'],
            'puerto' => ['required', 'integer', 'min:1', 'max:65535'],
            'cifrado' => ['required', Rule::in(['tls', 'ssl'])],
            'usuario' => ['nullable', 'email', 'max:190'],
            // En blanco = conservar la contraseña guardada.
            'password' => ['nullable', 'string', 'max:190'],
            'remitente_correo' => ['nullable', 'email', 'max:190'],
            'remitente_nombre' => ['nullable', 'string', 'max:120'],
        ]);

        $config = CorreoConfig::actual();

        $password = filled($datos['password'] ?? null) ? ['password' => $datos['password']] : [];
        unset($datos['password']);

        $config->fill($datos + $password)->save();

        return back()->with('exito', 'Configuración de correo guardada.');
    }

    public function probar(Request $request, CorreoService $correo): RedirectResponse
    {
        $datos = $request->validate(['destino' => ['required', 'email']]);

        $resultado = $correo->probar($datos['destino']);

        CorreoConfig::actual()->forceFill([
            'prueba_estado' => $resultado['ok'] ? 'ok' : 'error',
            'prueba_mensaje' => $resultado['mensaje'],
            'prueba_en' => now(),
        ])->save();

        return back()->with($resultado['ok'] ? 'exito' : 'error', $resultado['mensaje']);
    }
}
