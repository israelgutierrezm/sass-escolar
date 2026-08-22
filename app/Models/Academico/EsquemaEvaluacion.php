<?php

declare(strict_types=1);

namespace App\Models\Academico;

use App\Models\Concerns\TieneAuditoria;
use App\Models\ControlEscolar\CalificacionComponente;
use App\Models\Lms\Actividad;
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

    /**
     * Por qué no se puede retirar este componente, o null si sí se puede.
     *
     * ── Por qué hace falta preguntarlo ────────────────────────────────────
     * El modelo lleva `TieneAuditoria`, o sea BORRADO LÓGICO: la foránea de
     * `calificaciones_componente` —y el `nullOnDelete` de `actividades`— NUNCA
     * llegan a dispararse. Borrar un componente con calificaciones capturadas
     * no reventaba: devolvía éxito y dejaba los números colgando de una fila
     * invisible. Y es peor que un error, porque un error se ve: aquí el esquema
     * pasa a sumar 90 %, la calificación final deja de poderse calcular, y lo
     * capturado desaparece del cálculo sin dejar rastro en pantalla. Si además
     * alguien agrega otro componente para volver a sumar 100, el trabajo del
     * docente queda enterrado y todo parece normal.
     *
     * ── Un blanco no cuenta ───────────────────────────────────────────────
     * Guardar la hoja de captura escribe fila para cada alumno, con
     * `calificacion` en NULL donde el docente no llegó. Si esas contaran, abrir
     * la pantalla una vez dejaría el esquema congelado para siempre. Por eso se
     * pregunta por `capturadas()` y no por filas.
     *
     * ── Las actividades también lo sostienen ──────────────────────────────
     * Una actividad del LMS declara a qué componente pondera. Retirarle el
     * componente la deja suelta —ya hubo que escribir una migración para
     * reparar tres así— y su calificación sin destino. Se puede mover a otro
     * componente desde su propio formulario, que es la salida que el mensaje
     * propone.
     */
    public function motivoParaNoRetirarlo(): ?string
    {
        $calificaciones = CalificacionComponente::query()
            ->capturadas()
            ->where('esquema_evaluacion_id', $this->id)
            ->count();

        if ($calificaciones > 0) {
            return $calificaciones === 1
                ? "«{$this->nombreLegible()}» ya tiene 1 calificación capturada; retirarlo la dejaría fuera del cálculo. Vacía esa celda en la hoja de captura y vuelve a intentarlo."
                : "«{$this->nombreLegible()}» ya tiene {$calificaciones} calificaciones capturadas; retirarlo las dejaría fuera del cálculo. Vacía esas celdas en la hoja de captura y vuelve a intentarlo.";
        }

        $actividades = Actividad::query()
            ->where('esquema_evaluacion_id', $this->id)
            ->count();

        if ($actividades > 0) {
            return $actividades === 1
                ? "«{$this->nombreLegible()}» tiene 1 actividad que pondera en él. Muévela a otro componente antes de retirarlo."
                : "«{$this->nombreLegible()}» tiene {$actividades} actividades que ponderan en él. Muévelas a otro componente antes de retirarlo.";
        }

        return null;
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
