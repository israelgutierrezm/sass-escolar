<?php

declare(strict_types=1);

namespace App\Reportes\Definiciones;

use App\Models\Admisiones\SituacionAlumno;
use App\Reportes\DefinicionReporte;

/**
 * Los egresados, para acreditación y seguimiento.
 *
 * Incluye a los TITULADOS: un titulado es un egresado que además se tituló, y
 * dejarlos fuera daría un número menor del real justo en el reporte que una
 * escuela presume.
 */
class EgresadosPorGeneracion extends DefinicionReporte
{
    public function clave(): string
    {
        return 'egresados-por-generacion';
    }

    public function titulo(): string
    {
        return 'Egresados por generación';
    }

    public function descripcion(): string
    {
        return 'Las matrículas con situación de egreso o titulación. Incluye a los titulados a propósito: '
            .'un titulado egresó. NO dice cuántos están colocados —eso es el indicador de empleabilidad—.';
    }

    public function fuente(): string
    {
        return 'matriculas';
    }

    public function areaSugerida(): string
    {
        return 'control-escolar';
    }

    public function filtrosFijos(): array
    {
        /*
         * Por la BANDERA del catalogo, que es la mejor de las tres formas.
         *
         * `cuenta_como_egresado` es la misma columna que usa el indicador de
         * empleabilidad como denominador, asi que este reporte y aquel numero
         * no pueden discrepar. Y una escuela que agregue «egresado sin
         * documentos» entra sola con solo palomear la bandera.
         */
        return ['situacion_id' => SituacionAlumno::query()
            ->where('cuenta_como_egresado', true)
            ->pluck('id')
            ->all()];
    }

    public function columnasPorOmision(): ?array
    {
        return ['matricula', 'alumno', 'carrera', 'campus', 'generacion', 'situacion'];
    }

    public function ordenPorOmision(): ?array
    {
        return ['generacion', 'desc'];
    }
}
