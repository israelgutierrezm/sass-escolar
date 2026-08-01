<?php

declare(strict_types=1);

namespace App\Http\Requests;

/**
 * Alta de una materia en un plan: los campos de la asignatura nueva (heredados
 * de GuardarAsignaturaRequest) MÁS su ubicación en el plan (el periodo).
 * Así el alta y la edición comparten exactamente las mismas reglas de asignatura.
 *
 * Si la materia es obligatoria u optativa NO se captura aquí: lo dice el tipo de
 * la asignatura (`tipo_asignatura_id`), que ya valida el padre.
 */
class AgregarMateriaRequest extends GuardarAsignaturaRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'periodo' => ['nullable', 'integer', 'min:1', 'max:30'],
        ]);
    }
}
