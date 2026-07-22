<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validación del alta y edición de un aspirante.
 *
 * Captura datos de PERSONA y de ASPIRANTE en una sola pantalla, pero se
 * guardan en sus tablas respectivas: la identidad es de la persona y no se
 * duplica si ya existe.
 */
class GuardarAspiranteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // la autorización la aplica el middleware `can:` de la ruta
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $personaId = $this->route('aspirante')?->persona_id;

        return [
            // Persona
            'nombre' => ['required', 'string', 'max:255'],
            'primer_apellido' => ['required', 'string', 'max:255'],
            'segundo_apellido' => ['nullable', 'string', 'max:255'],
            'curp' => [
                'nullable',
                'string',
                'size:18',
                Rule::unique('personas', 'curp')->ignore($personaId)->whereNull('deleted_at'),
            ],
            'fecha_nacimiento' => ['nullable', 'date', 'before:today'],
            'sexo_id' => ['required', 'integer'],
            'genero_id' => ['nullable', 'integer'],
            'entidad_nacimiento_id' => ['nullable', 'integer'],
            'email' => ['nullable', 'email', 'max:150'],
            'celular' => ['nullable', 'string', 'max:20'],

            // Aspirante
            'oferta_interes_id' => ['nullable', 'integer', Rule::exists('oferta', 'id')->whereNull('deleted_at')],
            'campus_id' => ['nullable', 'integer', Rule::exists('campus', 'id')->whereNull('deleted_at')],
            'situacion_id' => ['required', 'integer', Rule::exists('situaciones_aspirante', 'id')->whereNull('deleted_at')],
            // `origen_id` es el catálogo del CRM; `origen` es el texto libre de
            // antes, que se conserva para no perder lo ya capturado.
            'origen_id' => ['nullable', 'integer', Rule::exists('origenes_aspirante', 'id')->whereNull('deleted_at')],
            'origen' => ['nullable', 'string', 'max:80'],
            'acepto_terminos' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'primer_apellido' => 'primer apellido',
            'segundo_apellido' => 'segundo apellido',
            'fecha_nacimiento' => 'fecha de nacimiento',
            'sexo_id' => 'sexo',
            'genero_id' => 'género',
            'entidad_nacimiento_id' => 'entidad de nacimiento',
            'oferta_interes_id' => 'oferta de interés',
            'campus_id' => 'campus',
            'situacion_id' => 'situación',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'curp.size' => 'La CURP debe tener exactamente 18 caracteres.',
            'curp.unique' => 'Ya existe una persona registrada con esa CURP.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'curp' => $this->filled('curp') ? mb_strtoupper(trim((string) $this->input('curp'))) : null,
        ]);
    }
}
