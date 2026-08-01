<?php

declare(strict_types=1);

namespace App\Models\Lms;

use App\Models\Concerns\TieneAuditoria;
use App\Models\ControlEscolar\AsignaturaGrupo;
use App\Models\Identidad\Persona;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * conversaciones (TENANT) — el chat de una materia impartida.
 *
 * O el canal del grupo —uno por materia, con todos dentro— o una conversación
 * directa entre dos personas de esa materia. Cuelga de la materia y no de las
 * personas: el mismo docente y el mismo alumno en dos materias distintas tienen
 * dos hilos distintos, que es lo que uno espera.
 */
class Conversacion extends Model
{
    use SoftDeletes;
    use TieneAuditoria;

    public const GRUPO = 'grupo';

    public const DIRECTA = 'directa';

    protected $table = 'conversaciones';

    protected $fillable = [
        'asignatura_grupo_id',
        'tipo',
        'persona_a_id',
        'persona_b_id',
        'ultimo_mensaje_en',
    ];

    protected function casts(): array
    {
        return ['ultimo_mensaje_en' => 'datetime'];
    }

    public function asignaturaGrupo(): BelongsTo
    {
        return $this->belongsTo(AsignaturaGrupo::class, 'asignatura_grupo_id');
    }

    public function mensajes(): HasMany
    {
        return $this->hasMany(Mensaje::class, 'conversacion_id');
    }

    public function lecturas(): HasMany
    {
        return $this->hasMany(ConversacionLectura::class, 'conversacion_id');
    }

    public function personaA(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'persona_a_id');
    }

    public function personaB(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'persona_b_id');
    }

    public function esDirecta(): bool
    {
        return $this->tipo === self::DIRECTA;
    }

    /**
     * Los dos ids de una directa, siempre ordenados.
     *
     * Se normaliza para que la misma pareja no abra dos conversaciones según
     * quién escriba primero: sin esto, el unique no sirve de nada.
     *
     * @return array{0: int, 1: int}
     */
    public static function pareja(int $unaPersona, int $otraPersona): array
    {
        return $unaPersona < $otraPersona
            ? [$unaPersona, $otraPersona]
            : [$otraPersona, $unaPersona];
    }

    /** En una directa, quién es el otro. */
    public function contraparte(int $personaId): ?int
    {
        if (! $this->esDirecta()) {
            return null;
        }

        return (int) $this->persona_a_id === $personaId
            ? (int) $this->persona_b_id
            : (int) $this->persona_a_id;
    }
}
