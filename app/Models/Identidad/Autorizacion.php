<?php

declare(strict_types=1);

namespace App\Models\Identidad;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * autorizaciones (TENANT) — lo que un familiar concede o niega.
 *
 * Una fila por VÍNCULO: quien autoriza es una persona concreta y su respuesta
 * es suya. Un alumno con padre y madre registrados recibe dos, y la escuela ve
 * cuántos contestaron en vez de un sí del que nadie se hace responsable.
 */
class Autorizacion extends Model
{
    use TieneAuditoria;

    protected $table = 'autorizaciones';

    protected $fillable = [
        'vinculo_familiar_id',
        'tipo_autorizacion_id',
        'titulo',
        'detalle',
        'fecha_limite',
        'concedida',
        'fecha_respuesta',
        'comentario',
    ];

    protected function casts(): array
    {
        return [
            'fecha_limite' => 'date',
            'fecha_respuesta' => 'datetime',
            'concedida' => 'boolean',
        ];
    }

    public function vinculo(): BelongsTo
    {
        return $this->belongsTo(TutorAlumno::class, 'vinculo_familiar_id');
    }

    public function tipo(): BelongsTo
    {
        return $this->belongsTo(TipoAutorizacion::class, 'tipo_autorizacion_id');
    }

    /** Todavía sin contestar. NULL es «no ha respondido», no «dijo que no». */
    public function scopePendientes(Builder $consulta): Builder
    {
        return $consulta->whereNull('concedida');
    }

    /**
     * ¿Se le pasó el plazo?
     *
     * Una vencida sin contestar NO se convierte en negada: se queda pendiente y
     * vencida, que es información distinta. Quien decida qué hacer con eso es la
     * escuela —hay trámites donde el silencio se acepta y otros donde no—, y el
     * sistema no puede elegir por ella.
     */
    public function estaVencida(): bool
    {
        return $this->fecha_limite !== null && $this->fecha_limite->lt(now()->startOfDay());
    }

    /**
     * ¿Todavía se puede contestar o cambiar la respuesta?
     *
     * Cambiarla es un derecho —un consentimiento de uso de imagen se revoca—,
     * pero no después del plazo: nadie des-autoriza la excursión el lunes
     * siguiente.
     */
    public function admiteRespuesta(): bool
    {
        return ! $this->estaVencida();
    }
}
