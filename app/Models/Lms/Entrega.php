<?php

declare(strict_types=1);

namespace App\Models\Lms;

use App\Models\Concerns\ReviveAlGuardar;
use App\Models\Concerns\TieneAuditoria;
use App\Models\ControlEscolar\Inscripcion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * entregas (TENANT) — lo que un alumno entregó de una actividad.
 *
 * Cuelga de la INSCRIPCIÓN, no de la persona: es lo que ata al alumno con esa
 * materia en ese ciclo, y es de donde ya cuelgan sus calificaciones y su
 * asistencia. Un renglón por alumno y actividad: reentregar reemplaza, no
 * acumula filas.
 */
class Entrega extends Model
{
    use ReviveAlGuardar;
    use SoftDeletes;
    use TieneAuditoria;

    public const PENDIENTE = 'pendiente';

    public const ENTREGADA = 'entregada';

    public const CALIFICADA = 'calificada';

    protected $table = 'entregas';

    protected $fillable = [
        'actividad_id',
        'inscripcion_id',
        'estado',
        'contenido',
        'entregada_en',
        'tarde',
        'calificacion',
        'retroalimentacion',
        'calificada_por',
        'calificada_en',
    ];

    protected function casts(): array
    {
        return [
            'entregada_en' => 'datetime',
            'calificada_en' => 'datetime',
            'tarde' => 'boolean',
            'calificacion' => 'decimal:2',
        ];
    }

    public function actividad(): BelongsTo
    {
        return $this->belongsTo(Actividad::class, 'actividad_id');
    }

    public function inscripcion(): BelongsTo
    {
        return $this->belongsTo(Inscripcion::class, 'inscripcion_id');
    }

    public function archivos(): HasMany
    {
        return $this->hasMany(EntregaArchivo::class, 'entrega_id');
    }

    /**
     * El desglose por criterio, cuando se calificó con rúbrica.
     *
     * La nota vive en `calificacion` y esto dice de dónde salió: ninguna
     * pantalla necesita saber de rúbricas para leer el número.
     */
    /**
     * Las piezas del portafolio, en el orden que decidió el alumno.
     *
     * Vacía para cualquier otro tipo de actividad, y no estorba: una tarea
     * normal simplemente no tiene ninguna.
     */
    public function evidencias(): HasMany
    {
        return $this->hasMany(PortafolioEvidencia::class, 'entrega_id')
            ->orderBy('orden')->orderBy('id');
    }

    public function porRubrica(): HasMany
    {
        return $this->hasMany(EntregaRubrica::class, 'entrega_id');
    }

    public function estaCalificada(): bool
    {
        return $this->estado === self::CALIFICADA && $this->calificacion !== null;
    }
}
