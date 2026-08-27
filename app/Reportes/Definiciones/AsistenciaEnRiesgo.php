<?php

declare(strict_types=1);

namespace App\Reportes\Definiciones;

use App\Reportes\DefinicionReporte;

/**
 * Quién está por perder el derecho a examen.
 *
 * ── El umbral lo pone quien consulta, y no es pereza ─────────────────────
 * El sistema NO tiene un ajuste que declare el mínimo de asistencia: se buscó
 * en `CatalogoAjustes` y no está. Escribir un 80 % aquí sería inventarle la
 * regla a la escuela, y peor, una que nadie podría cambiar sin tocar código. Se
 * entrega el dato y el filtro; el día que el ajuste exista, la columna sale de
 * ahí y no de un número escrito en esta clase.
 */
class AsistenciaEnRiesgo extends DefinicionReporte
{
    public function clave(): string
    {
        return 'asistencia-en-riesgo';
    }

    public function titulo(): string
    {
        return 'Asistencia en riesgo';
    }

    public function descripcion(): string
    {
        return 'Alumnos por materia cuya asistencia está por debajo del porcentaje que elijas. Una fila '
            .'es un ALUMNO EN UNA MATERIA: el derecho se pierde materia por materia. El porcentaje es '
            .'sobre las sesiones REGISTRADAS, no sobre el calendario, así que mira también la columna '
            .'de sesiones: un 50 % sobre dos clases no dice lo mismo que sobre cuarenta.';
    }

    public function fuente(): string
    {
        return 'asistencia-por-materia';
    }

    public function areaSugerida(): string
    {
        return 'control-escolar';
    }

    /** Sin umbral la pregunta no está hecha: no hay «riesgo» absoluto. */
    public function filtrosObligatorios(): array
    {
        return ['minimo_porcentaje'];
    }

    public function columnasPorOmision(): ?array
    {
        return ['matricula', 'alumno', 'materia', 'grupo', 'sesiones', 'faltas', 'justificadas', 'porcentaje'];
    }

    public function ordenPorOmision(): ?array
    {
        return ['faltas', 'desc'];
    }
}
