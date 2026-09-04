<?php

declare(strict_types=1);

namespace App\Reportes\Definiciones;

use App\Reportes\DefinicionReporte;

/**
 * Lo que se descartó, y por qué.
 *
 * ── Para qué sirve, que no es obvio ───────────────────────────────────────
 * Una regla cuyas señales se descartan el 80 % de las veces está MAL
 * CALIBRADA: le está haciendo perder el tiempo a quien revisa la cola, y a la
 * tercera semana nadie la mira. Agrupando por regla, este reporte es la tasa de
 * falsos positivos — que es la única forma de saberlo antes de que la escuela
 * deje de creerle a la bandeja entera.
 *
 * Y por eso el MOTIVO sale de un catálogo y no de un texto libre: con trescientas
 * frases distintas no hay nada que contar.
 */
class SenalesDescartadas extends DefinicionReporte
{
    public function clave(): string
    {
        return 'senales-descartadas';
    }

    public function titulo(): string
    {
        return 'Señales descartadas';
    }

    public function descripcion(): string
    {
        return 'Las que alguien revisó y decidió que no ameritan seguimiento, con su motivo. '
            .'Agrupado por regla es la tasa de falsos positivos: una regla que se descarta casi '
            .'siempre está mal calibrada.';
    }

    public function fuente(): string
    {
        return 'senales_permanencia';
    }

    public function areaSugerida(): string
    {
        return 'permanencia';
    }

    public function filtrosFijos(): array
    {
        return ['estado_triage' => ['descartada']];
    }

    public function columnasPorOmision(): ?array
    {
        return ['matricula', 'alumno', 'campus', 'categoria', 'regla', 'motivo_descarte',
            'revisada_por', 'revisada_en', 'dias_para_revisar'];
    }

    public function ordenPorOmision(): ?array
    {
        return ['revisada_en', 'desc'];
    }
}
