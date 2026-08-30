<?php

declare(strict_types=1);

namespace App\Reportes\Definiciones;

use App\Models\Admisiones\SituacionAlumno;
use App\Reportes\DefinicionReporte;

/**
 * Quiénes dejaron de estudiar, temporal o definitivamente.
 *
 * Misma fuente que «Alumnos inscritos»: lo único que cambia es qué situaciones
 * se fijan. Es lo que evita escribir una segunda clase de consulta casi igual
 * —y que la que se corrigiera no arreglara a la otra—.
 */
class BajasDeAlumnos extends DefinicionReporte
{
    public function clave(): string
    {
        return 'bajas-de-alumnos';
    }

    public function titulo(): string
    {
        return 'Bajas';
    }

    public function descripcion(): string
    {
        return 'Las matrículas dadas de baja, temporal o definitiva. NO dice cuándo se dieron de baja '
            .'—eso lo guarda la bitácora de situación—, dice quiénes están en esa situación ahora.';
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
         * Por CLAVE y no por id. Las dos bajas --temporal y definitiva--,
         * porque «cuantos se fueron» rara vez quiere decir solo las definitivas.
         *
         * Aqui NO hay bandera de catalogo que preguntar, al reves que en
         * egresados: se pregunta por las claves que el seeder siembra. Si una
         * escuela crea una tercera forma de baja, no entrara sola en este
         * reporte --y esta escrito para que se sepa--.
         */
        return ['situacion_id' => SituacionAlumno::query()
            ->whereIn('clave', ['baja_temporal', 'baja_definitiva'])
            ->pluck('id')
            ->all()];
    }

    public function columnasPorOmision(): ?array
    {
        return ['matricula', 'alumno', 'programa_academico', 'campus', 'situacion', 'periodo_actual'];
    }

    public function ordenPorOmision(): ?array
    {
        return ['matricula', 'asc'];
    }
}
