<?php

declare(strict_types=1);

namespace App\Http\Controllers\Emision;

use App\Http\Controllers\Controller;
use App\Models\Emision\TitulacionWsConfig;
use App\Services\Emision\ClienteTitulosSep;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Configuración del web service de Títulos Electrónicos de la SEP (Titulación →
 * Configuración → Web service).
 *
 * NO habla con la SEP: para eso está `ClienteTitulosSep`. Aquí solo se persiste la
 * configuración (dos juegos de credenciales + etapa activa), se enmascaran las
 * contraseñas antes de mandarlas al frontend y se prueba la conexión.
 */
class TitulacionWsConfigController extends Controller
{
    public function configuracion(): Response
    {
        return Inertia::render('Emision/ConfiguracionWs', [
            'config' => $this->paraElFrente(TitulacionWsConfig::actual()),
            'modo' => (string) config('services.titulos_sep.modo', 'fake'),
        ]);
    }

    public function guardar(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'etapa_activa' => ['required', Rule::in([TitulacionWsConfig::ETAPA_PRUEBAS, TitulacionWsConfig::ETAPA_PRODUCCION])],
            'usuario_pruebas' => ['nullable', 'string', 'max:150'],
            'usuario_produccion' => ['nullable', 'string', 'max:150'],
            // Las contraseñas son opcionales: si vienen vacías se conserva la guardada.
            'password_pruebas' => ['nullable', 'string', 'max:255'],
            'password_produccion' => ['nullable', 'string', 'max:255'],
        ]);

        $config = TitulacionWsConfig::actual();

        // Las contraseñas solo se pisan si el usuario capturó una nueva.
        $secretos = [];
        foreach (['password_pruebas', 'password_produccion'] as $campo) {
            if (filled($datos[$campo] ?? null)) {
                $secretos[$campo] = $datos[$campo];
            }
            unset($datos[$campo]);
        }

        $config->fill($datos + $secretos)->save();

        return back()->with('exito', 'Configuración del web service guardada.');
    }

    public function probar(ClienteTitulosSep $cliente): RedirectResponse
    {
        $resultado = $cliente->probarConexion();

        TitulacionWsConfig::actual()->forceFill([
            'conexion_estado' => $resultado['ok'] ? 'ok' : 'error',
            'conexion_mensaje' => $resultado['mensaje'],
            'conexion_probada_en' => now(),
        ])->save();

        return back()->with($resultado['ok'] ? 'exito' : 'error', $resultado['mensaje']);
    }

    /**
     * Las contraseñas NUNCA salen completas: solo se indica si existen.
     *
     * @return array<string, mixed>
     */
    private function paraElFrente(TitulacionWsConfig $config): array
    {
        return [
            'etapa_activa' => $config->etapa_activa,
            'usuario_pruebas' => $config->usuario_pruebas,
            'usuario_produccion' => $config->usuario_produccion,
            'tiene_password_pruebas' => filled($config->password_pruebas),
            'tiene_password_produccion' => filled($config->password_produccion),
            'hint_password_pruebas' => TitulacionWsConfig::enmascarar($config->password_pruebas),
            'hint_password_produccion' => TitulacionWsConfig::enmascarar($config->password_produccion),
            'credenciales_completas_activa' => $config->tieneCredenciales($config->etapa_activa),
            'conexion_estado' => $config->conexion_estado,
            'conexion_mensaje' => $config->conexion_mensaje,
            'conexion_probada_en' => $config->conexion_probada_en?->toDateTimeString(),
        ];
    }
}
