<?php

declare(strict_types=1);

namespace App\Models\ProcesosFormativos;

use App\Models\Concerns\TieneAuditoria;
use App\Models\Identidad\Usuario;
use App\Models\Lms\Rubrica;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Qué opinan del alumno: el supervisor, el coordinador y él mismo.
 *
 * ── La RÚBRICA se reusa del LMS ───────────────────────────────────────────
 * `/rubricas` ya sabe de criterios, niveles y puntos, y las de ámbito
 * «plataforma» son de la escuela. Estrenar aquí un segundo motor daría dos
 * sitios donde definir lo mismo, y el día que uno gane una función el otro se
 * quedaría atrás — es lo que le pasó a `vinculos_familiares` frente a
 * `tutores_alumno`.
 *
 * ── Y lo respondido se CONGELA ────────────────────────────────────────────
 * `respuestas` guarda el nivel elegido por criterio con su texto y sus puntos.
 * La rúbrica se puede editar después; releyendo la evaluación contra la de hoy,
 * diría algo que el supervisor nunca firmó. Mismo criterio que el emisor
 * congelado en la factura y que `esquema_evaluacion` materializado.
 *
 * ── Tres orígenes, y ninguno sustituye a otro ─────────────────────────────
 * El supervisor evalúa el desempeño, el coordinador el cumplimiento
 * institucional y el estudiante su propia experiencia. Con una sola evaluación
 * habría que elegir cuál de las tres cosas se mide.
 */
class EvaluacionProceso extends Model
{
    /** La del responsable en la organización receptora. */
    public const SUPERVISOR = 'supervisor';

    /** La de quien coordina el proceso en la escuela. */
    public const COORDINADOR = 'coordinador';

    /** La autoevaluación: qué le pareció a él. */
    public const ESTUDIANTE = 'estudiante';

    /** @var array<string, string> */
    public const ORIGENES = [
        self::SUPERVISOR => 'Del supervisor',
        self::COORDINADOR => 'Del coordinador',
        self::ESTUDIANTE => 'Autoevaluación',
    ];

    use SoftDeletes, TieneAuditoria;

    protected $table = 'evaluaciones_proceso';

    /**
     * El PUNTAJE no es asignable en masa: lo calcula el servidor a partir de
     * los niveles elegidos. Creyéndole a la petición, cualquiera se pondría el
     * puntaje que quisiera — es la lección de las rúbricas del LMS.
     */
    protected $fillable = [
        'expediente_id', 'origen', 'rubrica_id', 'comentarios', 'archivo_ruta', 'capturada_por',
    ];

    protected function casts(): array
    {
        return [
            'respuestas' => 'array',
            'firmada_en' => 'datetime',
            'puntaje' => 'decimal:2',
        ];
    }

    public function expediente(): BelongsTo
    {
        return $this->belongsTo(ExpedienteProceso::class, 'expediente_id');
    }

    public function rubrica(): BelongsTo
    {
        return $this->belongsTo(Rubrica::class, 'rubrica_id');
    }

    public function capturadaPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'capturada_por');
    }

    public function etiquetaOrigen(): string
    {
        return self::ORIGENES[$this->origen] ?? $this->origen;
    }

    /**
     * Sobre cuántos puntos se calificó.
     *
     * Sale de lo CONGELADO y no de la rúbrica de hoy: si no, editar la rúbrica
     * cambiaría el denominador de evaluaciones ya firmadas y un 45 de 50 se
     * convertiría en un 45 de 60 sin que nadie tocara nada.
     */
    public function total(): ?float
    {
        $respuestas = $this->respuestas;

        if (! is_array($respuestas) || $respuestas === []) {
            return null;
        }

        return round(array_sum(array_map(
            fn (array $r) => (float) ($r['maximo'] ?? 0),
            $respuestas,
        )), 2);
    }
}
