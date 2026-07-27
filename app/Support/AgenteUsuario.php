<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Lee el «User-Agent» y saca de él el navegador y el equipo (sistema
 * operativo) en texto para una persona, no para una máquina.
 *
 * Es un parser deliberadamente simple —unas cuantas familias conocidas—: sirve
 * para MOSTRAR «Chrome en Windows» en la bitácora, no para tomar decisiones. El
 * agente crudo se guarda aparte por si algún día hace falta más detalle.
 */
final class AgenteUsuario
{
    /**
     * @return array{navegador: ?string, equipo: ?string}
     */
    public static function analizar(?string $ua): array
    {
        $ua = trim((string) $ua);

        if ($ua === '') {
            return ['navegador' => null, 'equipo' => null];
        }

        return [
            'navegador' => self::navegador($ua),
            'equipo' => self::equipo($ua),
        ];
    }

    private static function navegador(string $ua): string
    {
        // El orden importa: Edge y Opera también dicen «Chrome»; Chrome también
        // dice «Safari». Se prueba del más específico al más genérico.
        return match (true) {
            str_contains($ua, 'Edg') => 'Edge',
            str_contains($ua, 'OPR') || str_contains($ua, 'Opera') => 'Opera',
            str_contains($ua, 'Firefox') => 'Firefox',
            str_contains($ua, 'SamsungBrowser') => 'Samsung Internet',
            str_contains($ua, 'Chrome') || str_contains($ua, 'CriOS') => 'Chrome',
            str_contains($ua, 'Safari') => 'Safari',
            default => 'Otro',
        };
    }

    private static function equipo(string $ua): string
    {
        return match (true) {
            str_contains($ua, 'iPhone') => 'iPhone',
            str_contains($ua, 'iPad') => 'iPad',
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Mac OS') || str_contains($ua, 'Macintosh') => 'Mac',
            str_contains($ua, 'Linux') => 'Linux',
            default => 'Otro',
        };
    }
}
