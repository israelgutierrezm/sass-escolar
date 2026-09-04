<?php

declare(strict_types=1);

namespace App\Reportes\Definiciones;

use App\Reportes\DefinicionReporte;

/**
 * Los que terminaron y esperan su constancia.
 *
 * Es una COLA DE TRABAJO, no un padrón: cada fila es alguien que ya hizo lo
 * suyo y espera un acto de la escuela. Por eso se ordena por la fecha de
 * conclusión —lo más viejo primero, que es lo que lleva más tiempo esperando—
 * y no por nombre.
 */
class ProcesosPorLiberar extends DefinicionReporte
{
    public function clave(): string
    {
        return 'procesos-por-liberar';
    }

    public function titulo(): string
    {
        return 'Por liberar';
    }

    public function descripcion(): string
    {
        return 'Los expedientes CONCLUIDOS que todavía no tienen constancia. Que estén aquí no '
            .'significa que ya se puedan liberar: el detalle de cada uno dice qué le falta —un '
            .'informe sin aceptar, una evaluación—.';
    }

    public function fuente(): string
    {
        return 'expedientes_formativos';
    }

    public function areaSugerida(): string
    {
        return 'procesos-formativos';
    }

    public function filtrosFijos(): array
    {
        return ['estado' => ['concluido']];
    }

    public function columnasPorOmision(): ?array
    {
        return ['matricula', 'alumno', 'programa_academico', 'tipo', 'organizacion',
            'horas_aprobadas', 'fecha_fin_programada'];
    }

    public function ordenPorOmision(): ?array
    {
        return ['fecha_fin_programada', 'asc'];
    }
}
