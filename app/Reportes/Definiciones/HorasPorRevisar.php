<?php

declare(strict_types=1);

namespace App\Reportes\Definiciones;

use App\Reportes\DefinicionReporte;

/**
 * Las jornadas que esperan que alguien las mire.
 *
 * Una cola de revisión que se atasca no falla ni avisa: el alumno sigue
 * capturando y su avance no sube, porque sólo lo APROBADO cuenta. Esto es lo
 * que lo hace visible.
 *
 * Se ordena por fecha ASCENDENTE —lo más viejo primero—: al revés, la cola se
 * atiende por lo recién llegado y lo de hace tres semanas nunca se toca.
 */
class HorasPorRevisar extends DefinicionReporte
{
    public function clave(): string
    {
        return 'horas-por-revisar';
    }

    public function titulo(): string
    {
        return 'Horas por revisar';
    }

    public function descripcion(): string
    {
        return 'Las jornadas capturadas que nadie ha aprobado ni rechazado. Mientras estén aquí NO '
            .'cuentan para las horas del alumno, aunque él las vea registradas.';
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
        return ['estado' => ['capturada']];
    }

    public function columnasPorOmision(): ?array
    {
        return ['fecha', 'matricula', 'alumno', 'tipo', 'organizacion', 'horario', 'horas', 'actividad'];
    }

    public function ordenPorOmision(): ?array
    {
        return ['fecha', 'asc'];
    }
}
