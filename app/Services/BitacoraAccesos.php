<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Identidad\BitacoraAcceso;
use App\Models\Identidad\Usuario;
use App\Support\AgenteUsuario;
use Illuminate\Http\Request;

/**
 * Escribe la bitácora de accesos.
 *
 * Un solo lugar para asentar quién entró, salió o pidió recuperar su cuenta, y
 * desde dónde. Nunca lanza: registrar el acceso no debe poder tumbar el login
 * ni el logout —si algo falla al escribir la bitácora, se traga el error y el
 * flujo sigue—.
 */
class BitacoraAccesos
{
    public function entrada(Usuario $usuario, Request $request): void
    {
        $this->registrar(BitacoraAcceso::ENTRADA, $request, $usuario);
    }

    public function salida(Usuario $usuario, Request $request): void
    {
        $this->registrar(BitacoraAcceso::SALIDA, $request, $usuario);
    }

    /**
     * @param  array<string, mixed>  $detalle
     */
    public function registrar(string $tipo, Request $request, ?Usuario $usuario = null, ?int $personaId = null, array $detalle = []): void
    {
        try {
            $agente = (string) $request->userAgent();
            $leido = AgenteUsuario::analizar($agente);

            BitacoraAcceso::create([
                'persona_id' => $personaId ?? $usuario?->persona_id,
                'usuario_id' => $usuario?->id,
                'tipo' => $tipo,
                'ip' => $request->ip(),
                'navegador' => $leido['navegador'],
                'equipo' => $leido['equipo'],
                'agente' => $agente !== '' ? mb_substr($agente, 0, 500) : null,
                'detalle' => $detalle === [] ? null : $detalle,
                'creado_en' => now(),
            ]);
        } catch (\Throwable) {
            // La bitácora es un efecto secundario: su falla no rompe el acceso.
        }
    }
}
