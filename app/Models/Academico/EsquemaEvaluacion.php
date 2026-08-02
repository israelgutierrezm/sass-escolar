<?php

declare(strict_types=1);

namespace App\Models\Academico;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * esquema_evaluacion (TENANT) — un componente de calificación por fila.
 */
class EsquemaEvaluacion extends Model
{
    use TieneAuditoria;

    protected $table = 'esquema_evaluacion';

    protected $fillable = [
        'plan_materia_id',
        'componente',
        'parcial',
        'porcentaje',
        'orden',
    ];

    protected function casts(): array
    {
        return [
            'porcentaje' => 'decimal:2',
        ];
    }

    public function planMateria(): BelongsTo
    {
        return $this->belongsTo(PlanMateria::class, 'plan_materia_id');
    }

    /** Cómo se le muestra este componente a quien no escribió la clave. */
    public function nombreLegible(): string
    {
        return self::legible($this->componente);
    }

    /** Con el parcial delante: «Parcial 2 · Examen». */
    public function etiquetaCompleta(): string
    {
        return "Parcial {$this->parcial} · ".$this->nombreLegible();
    }

    /**
     * `examen_p1` → «Examen», `participacion` → «Participación».
     *
     * El sufijo del parcial se quita porque el parcial ya lo dice el bloque en
     * el que está el renglón: repetirlo en cada línea es ruido.
     *
     * Las claves se escriben sin acentos y un `ucfirst` no puede inventarlos:
     * dejaría «Participacion» en un sistema que escribe en español correcto. Se
     * acentúan las que toda escuela usa; lo demás sale limpio aunque sin tilde,
     * que es lo más que se puede hacer sin adivinar.
     *
     * Sólo cambia lo que se MUESTRA: el dato guardado sigue siendo la clave con
     * la que control escolar armó el esquema.
     */
    public static function legible(?string $clave): string
    {
        if (blank($clave)) {
            return 'Componente';
        }

        $sinParcial = preg_replace('/[_\s-]*p\d+$/i', '', trim($clave)) ?? $clave;
        $conEspacios = mb_strtolower(trim(str_replace(['_', '-'], ' ', $sinParcial)));

        $conocidas = [
            'participacion' => 'Participación',
            'examen' => 'Examen',
            'examen final' => 'Examen final',
            'asistencia' => 'Asistencia',
            'tareas' => 'Tareas',
            'tarea' => 'Tarea',
            'practicas' => 'Prácticas',
            'practica' => 'Práctica',
            'proyecto' => 'Proyecto',
            'exposicion' => 'Exposición',
            'trabajo final' => 'Trabajo final',
            'evaluacion continua' => 'Evaluación continua',
        ];

        return $conocidas[$conEspacios] ?? ucfirst($conEspacios);
    }
}
