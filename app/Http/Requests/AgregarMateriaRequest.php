<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

/**
 * Alta de una materia en un plan: los campos de la asignatura nueva (heredados
 * de GuardarAsignaturaRequest) MÁS su ubicación en el plan (periodo y tipo).
 * Así el alta y la edición comparten exactamente las mismas reglas de asignatura.
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
            'tipo' => ['required', Rule::in(['obligatoria', 'optativa', 'tronco_comun'])],
        ]);
    }
}
