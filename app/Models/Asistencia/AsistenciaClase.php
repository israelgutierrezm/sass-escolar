<?php

declare(strict_types=1);

namespace App\Models\Asistencia;

use App\Models\Concerns\ReviveAlGuardar;
use App\Models\Concerns\TieneAuditoria;
use App\Models\ControlEscolar\Inscripcion;
use App\Models\Identidad\Persona;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * asistencia_clase (TENANT) — presencia académica del alumno en una materia.
 */
class AsistenciaClase extends Model
{
    use ReviveAlGuardar;
    use TieneAuditoria;

    /*
     * Los cuatro valores que de verdad se guardan en `estatus`.
     *
     * Los escribe `PaseListaController::ESTATUS`, que es la única puerta por la
     * que entra un pase de lista, y son los que hay en la base del demo.
     *
     * ── Ojo: esto decía `AUSENTE = 'ausente'` ─────────────────────────────
     * Lo guardado siempre fue `'falta'`, así que `scopeFaltas()` comparaba
     * contra un valor que no existe y devolvía CERO pase lo que pase. No se
     * notó porque nadie llamaba al scope todavía —es una trampa armada, no un
     * número mal en pantalla—, y habría mordido en el primer reporte de
     * inasistencias, que es justo lo que sigue. La constante se renombra
     * además de corregirse: `AUSENTE` no era el nombre del dato.
     */
    public const PRESENTE = 'presente';

    public const FALTA = 'falta';

    public const JUSTIFICADA = 'justificada';

    public const RETARDO = 'retardo';

    protected $table = 'asistencia_clase';

    protected $fillable = [
        'inscripcion_id',
        'fecha',
        // Qué sesión del día: `unica` en la mayoría de las materias, o
        // `teorica`/`practica` en las teórico-prácticas, que pasan lista dos
        // veces. Forma parte de la llave única junto con inscripción y fecha.
        'modalidad',
        'estatus',
        'registrada_por',
        'observacion',
    ];

    protected function casts(): array
    {
        return [
            'fecha' => 'date',
        ];
    }

    public function inscripcion(): BelongsTo
    {
        return $this->belongsTo(Inscripcion::class);
    }

    /** Docente que pasó lista. */
    public function registradaPor(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'registrada_por');
    }

    /**
     * Faltas que cuentan para efectos de reprobación por inasistencia: las
     * justificadas y los retardos NO cuentan como falta.
     */
    public function scopeFaltas(Builder $query): Builder
    {
        return $query->where('estatus', self::FALTA);
    }

    public function scopeDeInscripcion(Builder $query, int $inscripcionId): Builder
    {
        return $query->where('inscripcion_id', $inscripcionId);
    }
}
