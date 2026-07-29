<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Academico\PlanMateria;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Fuente ÚNICA de las reglas de una asignatura. La usan el alta (dentro de un
 * plan) y la edición desde la ficha del plan, para no repetir la validación en
 * varios controladores.
 *
 * En edición la clave única se ignora a sí misma: el id sale de la materia de
 * la ruta (`{materia}` → PlanMateria → asignatura_id). En alta no hay materia,
 * así que no se ignora nada.
 */
class GuardarAsignaturaRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Las rutas ya exigen `can:editar-catalogo-academico`.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $materia = $this->route('materia');
        $ignorar = $materia instanceof PlanMateria ? $materia->asignatura_id : null;

        return [
            'identificador' => ['required', 'string', 'max:50'],
            'clave' => ['required', 'string', 'max:50', Rule::unique('asignaturas', 'clave')->ignore($ignorar)->whereNull('deleted_at')],
            'nombre' => ['required', 'string', 'max:255'],
            'creditos' => ['required', 'numeric', 'min:0'],
            'tipo_asignatura_id' => ['required', 'integer', Rule::exists('tipos_asignatura', 'id')->whereNull('deleted_at')],
            'clasificacion_id' => ['nullable', 'integer', Rule::exists('clasificaciones_asignatura', 'id')->whereNull('deleted_at')],
            'area_id' => ['nullable', 'integer', Rule::exists('areas', 'id')->whereNull('deleted_at')],
            'horas_teoria' => ['nullable', 'integer', 'min:0'],
            'horas_practica' => ['nullable', 'integer', 'min:0'],
            'horas_acompanamiento' => ['nullable', 'integer', 'min:0'],
            'horas_independientes' => ['nullable', 'integer', 'min:0'],
            // Bloques de texto enriquecido tomados del catálogo de descriptores.
            'descriptores' => ['array'],
            'descriptores.*.descriptor_id' => ['required', 'integer', Rule::exists('descriptores', 'id')->whereNull('deleted_at')],
            'descriptores.*.contenido' => ['nullable', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['clave.unique' => 'Ya existe una asignatura con esa clave.'];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'tipo_asignatura_id' => 'tipo de asignatura',
            'clasificacion_id' => 'clasificación',
            'area_id' => 'área',
            'horas_teoria' => 'horas de teoría',
            'horas_practica' => 'horas de práctica',
            'horas_acompanamiento' => 'horas de acompañamiento',
            'horas_independientes' => 'horas independientes',
            'creditos' => 'créditos',
        ];
    }

    /**
     * Solo los campos que van a la tabla `asignaturas` (sin ubicación ni descriptores).
     *
     * @return array<string, mixed>
     */
    public function datosAsignatura(): array
    {
        return collect($this->validated())->only([
            'identificador', 'clave', 'nombre', 'creditos', 'tipo_asignatura_id',
            'clasificacion_id', 'area_id', 'horas_teoria', 'horas_practica',
            'horas_acompanamiento', 'horas_independientes',
        ])->all();
    }

    /**
     * Descriptores en el formato de `sync()`: [descriptor_id => ['contenido' => ...]].
     *
     * @return array<int, array{contenido: string|null}>
     */
    public function descriptoresSync(): array
    {
        return collect($this->validated()['descriptores'] ?? [])
            ->mapWithKeys(fn (array $d) => [$d['descriptor_id'] => ['contenido' => $d['contenido'] ?? null]])
            ->all();
    }
}
