<?php

declare(strict_types=1);

namespace App\Reportes\Definiciones;

use App\Reportes\DefinicionReporte;

/**
 * A quién mandó la escuela, a dónde y en qué va.
 *
 * Es la tabla que se presenta cuando alguien pregunta «¿qué movilidad hacen?»,
 * y la que permite ver el embudo completo: cuántos se postularon, cuántos
 * fueron aceptados y cuántos concluyeron.
 */
class MovilidadDelPeriodo extends DefinicionReporte
{
    public function clave(): string
    {
        return 'movilidad-del-periodo';
    }

    public function titulo(): string
    {
        return 'Movilidad saliente';
    }

    public function descripcion(): string
    {
        return 'Las postulaciones de alumnos NUESTROS a convocatorias de movilidad, con su etapa y su '
            .'estancia. Una fila es una POSTULACIÓN: quien se postuló a dos convocatorias sale dos '
            .'veces. NO incluye a los alumnos ENTRANTES —no tienen matrícula nuestra ni campus por '
            .'donde acotarlos—, así que este total no es «toda la movilidad de la escuela».';
    }

    public function fuente(): string
    {
        return 'movilidad-saliente';
    }

    public function areaSugerida(): string
    {
        return 'movilidad';
    }

    public function columnasPorOmision(): ?array
    {
        return ['matricula', 'alumno', 'programa_academico', 'convocatoria', 'institucion', 'periodo', 'etapa', 'promedio'];
    }

    public function ordenPorOmision(): ?array
    {
        return ['postulada_en', 'desc'];
    }
}
