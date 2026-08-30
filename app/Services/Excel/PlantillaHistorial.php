<?php

declare(strict_types=1);

namespace App\Services\Excel;

use App\Models\Academico\PlanEstudio;
use App\Models\Academico\PlanMateria;
use App\Models\ControlEscolar\Ciclo;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/**
 * Plantilla para cargar historial académico (calificaciones) de un programa académico/plan concreto.
 * La columna de materia es un desplegable con las asignaturas de ESE plan, así
 * no se puede capturar una materia ajena.
 */
class PlantillaHistorial extends PlantillaBase
{
    public function paraPlan(PlanEstudio $plan): string
    {
        $libro = new Spreadsheet;
        $libro->removeSheetByIndex(0);

        $materias = PlanMateria::query()->where('plan_id', $plan->id)
            ->orderBy('periodo')->orderBy('clave_en_plan')->pluck('clave_en_plan')->filter()->values()->all();

        $rangos = $this->listas($libro, [
            'materias' => $materias,
            'ciclos' => Ciclo::query()->orderBy('clave')->pluck('clave')->all(),
        ]);

        $this->instrucciones($libro, [
            "Historial académico del plan: {$plan->nombre}.",
            'Una fila por materia cursada. La Matrícula debe existir en el sistema.',
            'La Materia es la clave de la asignatura en este plan (desplegable).',
            'El estatus (aprobada/reprobada) se deriva de la calificación según el mínimo aprobatorio del plan.',
        ]);

        $this->hoja($libro, 'Historial académico', [
            ['Matrícula *', null], ['Materia (clave en el plan) *', $rangos['materias']],
            ['Calificación', null], ['Ciclo (clave) *', $rangos['ciclos']],
        ], [
            'L20200001', $materias[0] ?? 'MAT-101', 8.5, $this->primero(Ciclo::class, 'clave'),
        ]);

        $libro->setActiveSheetIndexByName('Instrucciones');

        return $this->guardar($libro, 'plan_historial');
    }
}
