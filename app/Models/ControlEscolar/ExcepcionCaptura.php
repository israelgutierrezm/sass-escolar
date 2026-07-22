<?php

declare(strict_types=1);

namespace App\Models\ControlEscolar;

use App\Models\Concerns\TieneAuditoria;
use App\Models\Identidad\Persona;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * excepciones_captura (TENANT) — a este docente se le reabre esta materia.
 *
 * Es el permiso especial que un administrador concede cuando la ventana ya
 * cerró. Guarda hasta cuándo, por qué y quién lo autorizó: reabrir la captura
 * es una decisión administrativa, y después alguien va a preguntar quién la
 * tomó.
 */
class ExcepcionCaptura extends Model
{
    use TieneAuditoria;

    protected $table = 'excepciones_captura';

    protected $fillable = [
        'ventana_id',
        'asignatura_grupo_id',
        'persona_id',
        'hasta',
        'motivo',
        'autorizada_por',
    ];

    protected function casts(): array
    {
        return [
            'hasta' => 'date',
        ];
    }

    public function ventana(): BelongsTo
    {
        return $this->belongsTo(VentanaCaptura::class, 'ventana_id');
    }

    public function asignaturaGrupo(): BelongsTo
    {
        return $this->belongsTo(AsignaturaGrupo::class, 'asignatura_grupo_id');
    }

    /** El docente beneficiado. NULL = cualquiera de esa materia. */
    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'persona_id');
    }

    public function autorizadaPor(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'autorizada_por');
    }

    public function sigueVigente(?string $fecha = null): bool
    {
        $fecha ??= now()->toDateString();

        return $fecha <= $this->hasta->toDateString();
    }

    /** ¿Aplica a esta persona? Sin persona fijada, aplica a cualquiera. */
    public function alcanzaA(?int $personaId): bool
    {
        return $this->persona_id === null || $this->persona_id === $personaId;
    }
}
