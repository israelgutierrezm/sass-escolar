<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Identidad\Tema;
use App\Models\Identidad\Usuario;
use App\Models\Identidad\UsuarioTemaOverride;

/**
 * Resuelve qué colores ve un usuario, en cascada:
 *
 *   tema por defecto de la escuela → tema elegido por el usuario → sus
 *   overrides personales (solo si ese tema los permite).
 *
 * Devuelve los tokens ya combinados para que el front solo tenga que
 * inyectarlos como CSS custom properties.
 */
class ResolutorTema
{
    /**
     * @return array<string, mixed>
     */
    public function paraUsuario(?Usuario $usuario): array
    {
        $tema = $this->temaElegido($usuario);

        if ($tema === null) {
            return ['clave' => null, 'nombre' => null, 'tokens' => [], 'permite_override' => false, 'disponibles' => []];
        }

        $tokens = $tema->tokens->pluck('valor', 'token')->all();

        if ($usuario !== null && $tema->permite_override_usuario) {
            $tokens = [...$tokens, ...$this->overrides($usuario)];
        }

        return [
            'clave' => $tema->clave,
            'nombre' => $tema->nombre,
            'tokens' => $tokens,
            'permite_override' => (bool) $tema->permite_override_usuario,
            // Se envían todos para que el selector no requiera otra petición.
            'disponibles' => Tema::query()
                ->with('tokens')
                ->orderByDesc('es_default')
                ->orderBy('nombre')
                ->get()
                ->map(fn (Tema $otro) => [
                    'id' => $otro->id,
                    'clave' => $otro->clave,
                    'nombre' => $otro->nombre,
                    'es_default' => (bool) $otro->es_default,
                    // Una muestra para pintar la vista previa del selector.
                    'muestra' => [
                        'barra_lateral' => $otro->tokens->firstWhere('token', 'barra_lateral')?->valor,
                        'acento' => $otro->tokens->firstWhere('token', 'acento')?->valor,
                        'fondo' => $otro->tokens->firstWhere('token', 'fondo')?->valor,
                    ],
                ])
                ->all(),
        ];
    }

    /**
     * El tema del usuario si eligió uno y sigue existiendo; si no, el que la
     * escuela marcó por defecto.
     */
    private function temaElegido(?Usuario $usuario): ?Tema
    {
        if ($usuario?->tema_id !== null) {
            $tema = Tema::query()->with('tokens')->find($usuario->tema_id);

            if ($tema !== null) {
                return $tema;
            }
        }

        return Tema::query()->with('tokens')->where('es_default', true)->first()
            ?? Tema::query()->with('tokens')->first();
    }

    /**
     * @return array<string, string>
     */
    private function overrides(Usuario $usuario): array
    {
        return UsuarioTemaOverride::query()
            ->where('usuario_id', $usuario->id)
            ->pluck('valor', 'token')
            ->all();
    }
}
