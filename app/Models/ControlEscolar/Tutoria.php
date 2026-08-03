<?php

declare(strict_types=1);

namespace App\Models\ControlEscolar;

use App\Models\Concerns\ReviveAlGuardar;
use App\Models\Concerns\TieneAuditoria;
use App\Models\Identidad\Persona;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * tutorias (TENANT) — a qué alumno acompaña un tutor educativo, y en qué ciclo.
 *
 * No confundir con `tutores_alumno`, que es el vínculo FAMILIAR. Aquélla dice
 * quién es el papá; ésta, quién da seguimiento académico.
 */
class Tutoria extends Model
{
    /*
     * `ReviveAlGuardar` porque la llave única (alumno + ciclo) NO conoce el
     * borrado suave: al retirar una tutoría la fila sigue ahí con `deleted_at`,
     * y un `updateOrCreate` normal —que no ve los borrados— intentaría insertar
     * otra igual y chocaría contra la unique. Reasignar a un alumno al que se le
     * quitó el tutor es justo el caso frecuente.
     */
    use ReviveAlGuardar;
    use TieneAuditoria;

    protected $table = 'tutorias';

    protected $fillable = [
        'tutor_persona_id',
        'alumno_persona_id',
        'ciclo_id',
        'activa',
    ];

    protected function casts(): array
    {
        return [
            'activa' => 'boolean',
        ];
    }

    public function tutor(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'tutor_persona_id');
    }

    public function alumno(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'alumno_persona_id');
    }

    public function ciclo(): BelongsTo
    {
        return $this->belongsTo(Ciclo::class, 'ciclo_id');
    }

    /**
     * Las tutorías vigentes de un tutor.
     *
     * Vigente = activa. El ciclo NO se filtra aquí: una escuela puede no
     * llevarlos capturados, y en ese caso filtrar por «ciclo actual» dejaría al
     * tutor sin ningún tutorado a la vista sin decirle por qué.
     */
    public function scopeDe(Builder $q, int $tutorPersonaId): Builder
    {
        return $q->where('tutor_persona_id', $tutorPersonaId)->where('activa', true);
    }
}
