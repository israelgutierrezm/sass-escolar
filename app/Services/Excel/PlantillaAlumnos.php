<?php

declare(strict_types=1);

namespace App\Services\Excel;

use App\Models\Academico\Campus;
use App\Models\Academico\PlanEstudio;
use App\Models\Academico\ProgramaAcademico;
use App\Models\Admisiones\SituacionAlumno;
use App\Models\ControlEscolar\Ciclo;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/**
 * Plantilla de carga masiva de alumnos, en dos variantes: solo alumnos, o
 * alumnos con calificaciones (agrega una hoja «Calificaciones» para el historial académico).
 */
class PlantillaAlumnos extends PlantillaBase
{
    public function generar(bool $conCalificaciones = false): string
    {
        $libro = new Spreadsheet;
        $libro->removeSheetByIndex(0);

        $rangos = $this->listas($libro, [
            'situaciones' => SituacionAlumno::query()->orderBy('nombre')->pluck('nombre')->all(),
            'programas_academicos' => ProgramaAcademico::query()->orderBy('clave')->pluck('clave')->all(),
            'planes' => PlanEstudio::query()->orderBy('clave')->pluck('clave')->all(),
            'campus' => Campus::query()->orderBy('clave')->pluck('clave')->all(),
            'ciclos' => Ciclo::query()->orderBy('clave')->pluck('clave')->all(),
        ]);

        $lineas = [
            'Una fila por alumno. La primera fila (gris) es un ejemplo: bórrala o reemplázala.',
            'Los encabezados con * son obligatorios. CURP identifica a la persona (no se duplica).',
            'Programa académico, Plan y Campus (clave) deben existir y formar una oferta válida.',
        ];
        if ($conCalificaciones) {
            $lineas[] = 'En la hoja «Calificaciones»: la Matrícula debe estar en «Alumnos» o ya existir; la Materia es la clave de la asignatura en el plan del alumno.';
            $lineas[] = 'El estatus (aprobada/reprobada) se deriva de la calificación según el mínimo aprobatorio del plan.';
        }
        $this->instrucciones($libro, $lineas);

        $this->hoja($libro, 'Alumnos', [
            ['Nombre *', null], ['Primer apellido *', null], ['Segundo apellido', null],
            ['CURP *', null], ['Correo', null], ['Fecha de nacimiento (AAAA-MM-DD)', null], ['Celular', null],
            ['Matrícula *', null], ['Generación', null], ['Fecha de ingreso (AAAA-MM-DD)', null],
            ['Programa académico (clave) *', $rangos['programas_academicos']], ['Plan (clave) *', $rangos['planes']],
            ['Campus (clave) *', $rangos['campus']], ['Situación', $rangos['situaciones']],
        ], [
            'Regina', 'González', 'Rivera', 'GORR020819MDFNVG09', 'regina.gonzalez@alumnos.escuela.mx',
            '2002-08-19', '5599887766', 'L20200001', '2020', '2020-08-01',
            $this->primero(ProgramaAcademico::class, 'clave'), $this->primero(PlanEstudio::class, 'clave'),
            $this->primero(Campus::class, 'clave'), $this->primero(SituacionAlumno::class),
        ]);

        if ($conCalificaciones) {
            $this->hoja($libro, 'Calificaciones', [
                ['Matrícula *', null], ['Materia (clave en el plan) *', null],
                ['Calificación', null], ['Ciclo (clave) *', $rangos['ciclos']],
            ], ['L20200001', 'MAT-101', 8.5, $this->primero(Ciclo::class, 'clave')]);
        }

        $libro->setActiveSheetIndexByName('Instrucciones');

        return $this->guardar($libro, 'plan_alumnos');
    }
}
