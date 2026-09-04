<?php

declare(strict_types=1);

namespace App\Reportes\Definiciones;

use App\Reportes\DefinicionReporte;

/**
 * Los que se pasaron de fecha y siguen abiertos.
 *
 * La cola que nadie ve hasta que se mira: un expediente cuyo periodo terminó y
 * que nadie cerró se queda ahí para siempre, contando como «en curso» en todos
 * los demás reportes. Su alerta diaria avisa al alumno; esto es lo que la
 * escuela usa para vaciar la cola.
 */
class ProcesosVencidos extends DefinicionReporte
{
    public function clave(): string
    {
        return 'procesos-vencidos';
    }

    public function titulo(): string
    {
        return 'Procesos que se pasaron de fecha';
    }

    public function descripcion(): string
    {
        return 'Expedientes cuyo periodo ya terminó y que siguen en curso o suspendidos. Cada uno '
            .'necesita una decisión: ampliar el periodo, suspenderlo o darlo por concluido.';
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
        return ['vencidos' => true];
    }

    public function columnasPorOmision(): ?array
    {
        return ['matricula', 'alumno', 'programa_academico', 'tipo', 'organizacion',
            'estado', 'horas_aprobadas', 'avance', 'fecha_fin_programada'];
    }

    public function ordenPorOmision(): ?array
    {
        return ['fecha_fin_programada', 'asc'];
    }
}
