<?php

declare(strict_types=1);

namespace Database\Seeders\Tenant;

use App\Models\Identidad\Tema;
use Illuminate\Database\Seeder;

/**
 * Temas visuales de la escuela (TENANT-CONFIG).
 *
 * Los colores NO viven en JSON: cada token es una fila de `tema_tokens`, así
 * que la escuela puede editar un color con un UPDATE directo y consultarlos
 * como cualquier otro dato. El front los inyecta como CSS custom properties.
 *
 * Cascada de resolución: tema por defecto de la escuela → tema elegido por el
 * usuario → sus overrides personales (solo si el tema los permite).
 *
 * Idempotente por clave de tema y por (tema, token).
 */
class TemaSeeder extends Seeder
{
    /**
     * Juego de tokens que todo tema debe definir. Mantenerlos completos evita
     * que un tema herede colores sueltos de otro y se vea inconsistente.
     */
    private const TEMAS = [
        [
            'clave' => 'indigo',
            'nombre' => 'Índigo',
            'es_default' => true,
            'permite_override_usuario' => true,
            'tokens' => [
                'barra_lateral' => '#1E1B4B',
                'barra_lateral_suave' => '#312E81',
                'barra_lateral_texto' => '#C7D2FE',
                'barra_lateral_activo' => '#4F46E5',
                'barra_superior' => '#FFFFFF',
                'barra_superior_texto' => '#1E293B',
                'acento' => '#4F46E5',
                'acento_texto' => '#FFFFFF',
                'fondo' => '#F1F5F9',
                'superficie' => '#FFFFFF',
                'borde' => '#E2E8F0',
                'texto' => '#0F172A',
                'texto_suave' => '#64748B',
            ],
        ],
        [
            'clave' => 'medianoche',
            'nombre' => 'Medianoche',
            'es_default' => false,
            'permite_override_usuario' => true,
            'tokens' => [
                'barra_lateral' => '#0B1120',
                'barra_lateral_suave' => '#111827',
                'barra_lateral_texto' => '#94A3B8',
                'barra_lateral_activo' => '#38BDF8',
                'barra_superior' => '#111827',
                'barra_superior_texto' => '#E2E8F0',
                'acento' => '#38BDF8',
                'acento_texto' => '#0B1120',
                'fondo' => '#0F172A',
                'superficie' => '#1E293B',
                'borde' => '#334155',
                'texto' => '#F1F5F9',
                'texto_suave' => '#94A3B8',
            ],
        ],
        [
            'clave' => 'esmeralda',
            'nombre' => 'Esmeralda',
            'es_default' => false,
            'permite_override_usuario' => true,
            'tokens' => [
                'barra_lateral' => '#064E3B',
                'barra_lateral_suave' => '#065F46',
                'barra_lateral_texto' => '#A7F3D0',
                'barra_lateral_activo' => '#10B981',
                'barra_superior' => '#FFFFFF',
                'barra_superior_texto' => '#1E293B',
                'acento' => '#059669',
                'acento_texto' => '#FFFFFF',
                'fondo' => '#F0FDF4',
                'superficie' => '#FFFFFF',
                'borde' => '#D1FAE5',
                'texto' => '#052E23',
                'texto_suave' => '#4B7A6A',
            ],
        ],
        [
            'clave' => 'grafito',
            'nombre' => 'Grafito',
            'es_default' => false,
            'permite_override_usuario' => true,
            'tokens' => [
                'barra_lateral' => '#18181B',
                'barra_lateral_suave' => '#27272A',
                'barra_lateral_texto' => '#A1A1AA',
                'barra_lateral_activo' => '#F59E0B',
                'barra_superior' => '#FFFFFF',
                'barra_superior_texto' => '#18181B',
                'acento' => '#D97706',
                'acento_texto' => '#FFFFFF',
                'fondo' => '#FAFAFA',
                'superficie' => '#FFFFFF',
                'borde' => '#E4E4E7',
                'texto' => '#18181B',
                'texto_suave' => '#71717A',
            ],
        ],
        [
            'clave' => 'alto_contraste',
            'nombre' => 'Alto contraste',
            'es_default' => false,
            // Sin overrides: personalizar colores rompería la accesibilidad
            // que es justo la razón de ser de este tema.
            'permite_override_usuario' => false,
            'tokens' => [
                'barra_lateral' => '#000000',
                'barra_lateral_suave' => '#1A1A1A',
                'barra_lateral_texto' => '#FFFFFF',
                'barra_lateral_activo' => '#FFD400',
                'barra_superior' => '#000000',
                'barra_superior_texto' => '#FFFFFF',
                'acento' => '#0000CC',
                'acento_texto' => '#FFFFFF',
                'fondo' => '#FFFFFF',
                'superficie' => '#FFFFFF',
                'borde' => '#000000',
                'texto' => '#000000',
                'texto_suave' => '#333333',
            ],
        ],
    ];

    /**
     * Temas de una versión anterior del seeder, con un juego de tokens
     * incompleto. Se retiran porque se verían rotos junto a los actuales.
     */
    private const OBSOLETOS = ['claro', 'oscuro'];

    public function run(): void
    {
        Tema::query()->whereIn('clave', self::OBSOLETOS)->delete();

        // Solo un tema puede ser el predeterminado de la escuela; si quedaran
        // dos, cuál gana dependería del orden de los ids.
        Tema::query()->update(['es_default' => false]);

        foreach (self::TEMAS as $datos) {
            $tokens = $datos['tokens'];
            unset($datos['tokens']);

            $tema = Tema::query()->updateOrCreate(['clave' => $datos['clave']], $datos);

            foreach ($tokens as $token => $valor) {
                $tema->tokens()->updateOrCreate(['token' => $token], ['valor' => $valor]);
            }
        }
    }
}
