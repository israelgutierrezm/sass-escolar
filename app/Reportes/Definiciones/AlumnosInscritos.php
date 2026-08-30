<?php

declare(strict_types=1);

namespace App\Reportes\Definiciones;

use App\Models\Admisiones\SituacionAlumno;
use App\Reportes\DefinicionReporte;

/**
 * Quiénes están estudiando HOY.
 *
 * El caso más pedido, y el que más se malinterpreta: no dice cuántos se
 * inscribieron este ciclo, dice cuántos están activos ahora mismo.
 */
class AlumnosInscritos extends DefinicionReporte
{
    public function clave(): string
    {
        return 'alumnos-inscritos';
    }

    public function titulo(): string
    {
        return 'Alumnos inscritos';
    }

    public function descripcion(): string
    {
        return 'Las matrículas con situación activa, hoy. NO dice cuántos se inscribieron en un ciclo '
            .'—para eso está el filtro de fecha de ingreso— ni cuenta personas: quien estudia dos programas académicos aparece dos veces.';
    }

    public function fuente(): string
    {
        return 'matriculas';
    }

    public function areaSugerida(): string
    {
        return 'control-escolar';
    }

    /**
     * La situación ACTIVA es fija, y eso es lo que lo hace un reporte.
     *
     * Sugerirla como filtro por omisión no bastaría: cualquiera la quitaría sin
     * darse cuenta y el «reporte de inscritos» acabaría incluyendo bajas en una
     * junta de consejo.
     */
    public function filtrosFijos(): array
    {
        /*
         * Se resuelve por CLAVE, no por el id 1.
         *
         * Un id cableado funciona hoy y deja de funcionar en silencio el dia que
         * alguien resiembre el catalogo o lo reordene: el reporte de inscritos
         * empezaria a contar bajas y nadie lo notaria hasta una junta. Es la
         * misma leccion que la bolsa de trabajo dejo escrita.
         */
        return ['situacion_id' => SituacionAlumno::query()->where('clave', 'activo')->pluck('id')->all()];
    }

    public function columnasPorOmision(): ?array
    {
        return ['matricula', 'alumno', 'programa_academico', 'campus', 'generacion', 'periodo_actual'];
    }

    public function ordenPorOmision(): ?array
    {
        return ['matricula', 'asc'];
    }
}
