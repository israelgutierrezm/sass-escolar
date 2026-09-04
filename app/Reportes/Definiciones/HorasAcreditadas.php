<?php

declare(strict_types=1);

namespace App\Reportes\Definiciones;

use App\Reportes\DefinicionReporte;

/**
 * Las horas que de verdad cuentan.
 *
 * Es el número que una escuela presume ante una acreditadora: cuánto trabajo
 * comunitario aportó su alumnado. Sólo las APROBADAS, que es la única
 * definición de «horas del alumno» que este módulo tiene.
 */
class HorasAcreditadas extends DefinicionReporte
{
    public function clave(): string
    {
        return 'horas-acreditadas';
    }

    public function titulo(): string
    {
        return 'Horas acreditadas';
    }

    public function descripcion(): string
    {
        return 'Las jornadas aprobadas, con su total al pie. Lo capturado y sin revisar NO entra: '
            .'para eso está «Horas por revisar».';
    }

    public function fuente(): string
    {
        return 'horas_formativas';
    }

    public function areaSugerida(): string
    {
        return 'procesos-formativos';
    }

    public function filtrosFijos(): array
    {
        return ['estado' => ['aprobada']];
    }

    public function columnasPorOmision(): ?array
    {
        return ['fecha', 'matricula', 'alumno', 'campus', 'tipo', 'organizacion', 'horas'];
    }

    public function ordenPorOmision(): ?array
    {
        return ['fecha', 'desc'];
    }
}
