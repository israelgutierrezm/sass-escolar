<?php

declare(strict_types=1);

namespace App\Services\Excel;

use App\Models\Academico\Campus;
use App\Models\ControlEscolar\SituacionDocente;
use App\Models\ControlEscolar\TipoDocente;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/** Plantilla de carga masiva de docentes. */
class PlantillaDocentes extends PlantillaBase
{
    public function generar(): string
    {
        $libro = new Spreadsheet;
        $libro->removeSheetByIndex(0);

        $rangos = $this->listas($libro, [
            'tipos' => TipoDocente::query()->orderBy('nombre')->pluck('nombre')->all(),
            'situaciones' => SituacionDocente::query()->orderBy('nombre')->pluck('nombre')->all(),
            'campus' => Campus::query()->orderBy('nombre')->pluck('nombre')->all(),
        ]);

        $this->instrucciones($libro, [
            'Una fila por docente. La primera fila (gris) es un ejemplo: bórrala o reemplázala.',
            'Los encabezados con * son obligatorios. CURP y correo identifican a la persona (no se duplica).',
            'Tipo, Situación y Campus solo aceptan valores del catálogo (lista desplegable).',
            'La persona pasa a ser usuario con rol docente; podrá acceder cuando se le configure contraseña.',
        ]);

        $this->hoja($libro, 'Docentes', [
            ['Nombre *', null], ['Primer apellido *', null], ['Segundo apellido', null],
            ['CURP *', null], ['Correo *', null], ['RFC', null],
            ['Fecha de nacimiento (AAAA-MM-DD)', null], ['Celular', null],
            ['Clave de profesor', null], ['Cédula profesional', null],
            ['Tipo de docente', $rangos['tipos']], ['Situación', $rangos['situaciones']], ['Campus', $rangos['campus']],
        ], [
            'Roberto', 'Guzmán', 'Herrera', 'GUHR910730HDFZRB09', 'roberto.guzman@escuela.mx', 'GUHR910730',
            '1991-07-30', '5512345678', 'PROF-001', '1234567',
            $this->primero(TipoDocente::class), $this->primero(SituacionDocente::class), $this->primero(Campus::class),
        ]);

        $libro->setActiveSheetIndexByName('Instrucciones');

        return $this->guardar($libro, 'plan_docentes');
    }
}
