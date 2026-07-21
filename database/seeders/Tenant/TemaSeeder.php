<?php

declare(strict_types=1);

namespace Database\Seeders\Tenant;

use App\Models\Identidad\Tema;
use Illuminate\Database\Seeder;

/**
 * Temas visuales base de la escuela (TENANT-CONFIG). Se ejecuta por tenant.
 * Cada tema trae sus tokens de color como filas (relacional, sin JSON).
 * Idempotente por clave de tema y (tema, token).
 */
class TemaSeeder extends Seeder
{
    public function run(): void
    {
        $temas = [
            [
                'clave' => 'claro',
                'nombre' => 'Claro',
                'es_default' => true,
                'permite_override_usuario' => true,
                'tokens' => [
                    'barra_superior' => '#33417A',
                    'barra_lateral' => '#33417A',
                    'color_fondo' => '#F5F6FA',
                    'color_primario' => '#33417A',
                    'acento' => '#7A1737',
                    'texto_logo' => 'claro',
                    'densidad' => 'normal',
                ],
            ],
            [
                'clave' => 'oscuro',
                'nombre' => 'Oscuro',
                'es_default' => false,
                'permite_override_usuario' => true,
                'tokens' => [
                    'barra_superior' => '#12141C',
                    'barra_lateral' => '#12141C',
                    'color_fondo' => '#1B1D26',
                    'color_primario' => '#8B9BE8',
                    'acento' => '#E86A8B',
                    'texto_logo' => 'claro',
                    'densidad' => 'normal',
                ],
            ],
            [
                'clave' => 'alto_contraste',
                'nombre' => 'Alto contraste',
                'es_default' => false,
                'permite_override_usuario' => false,
                'tokens' => [
                    'barra_superior' => '#000000',
                    'barra_lateral' => '#000000',
                    'color_fondo' => '#FFFFFF',
                    'color_primario' => '#000000',
                    'acento' => '#0000FF',
                    'texto_logo' => 'oscuro',
                    'densidad' => 'normal',
                ],
            ],
        ];

        foreach ($temas as $datos) {
            $tokens = $datos['tokens'];
            unset($datos['tokens']);

            $tema = Tema::query()->updateOrCreate(
                ['clave' => $datos['clave']],
                $datos,
            );

            foreach ($tokens as $token => $valor) {
                $tema->tokens()->updateOrCreate(
                    ['token' => $token],
                    ['valor' => $valor],
                );
            }
        }
    }
}
