<?php

declare(strict_types=1);

namespace App\Models\Admisiones;

use App\Models\Academico\Campus;
use App\Models\Concerns\TieneAuditoria;
use App\Models\Identidad\Persona;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * asesores (TENANT) — quién da seguimiento comercial. PK = persona_id.
 *
 * ── No es un rol, es una ASIGNACIÓN ────────────────────────────────────────
 * El rol dice qué PUEDE hacer alguien (`ver-mis-prospectos`); estar en esta
 * tabla dice que la escuela lo puso a atender prospectos. Es el mismo par que
 * separa al docente de sus materias: con el permiso solo, cualquiera con ese
 * rol entraría al CRM y no habría a quién repartirle nada.
 *
 * Por eso `situacion_id` y no una bandera: un asesor que se va de vacaciones se
 * pone inactivo y deja de entrar en el reparto SIN perder los prospectos que ya
 * atendía ni su historial.
 */
class Asesor extends Model
{
    use TieneAuditoria;

    protected $table = 'asesores';

    protected $primaryKey = 'persona_id';

    public $incrementing = false;

    protected $fillable = [
        'persona_id',
        'clave_asesor',
        'situacion_id',
    ];

    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'persona_id');
    }

    public function situacion(): BelongsTo
    {
        return $this->belongsTo(SituacionAsesor::class, 'situacion_id');
    }

    public function campus(): BelongsToMany
    {
        return $this->belongsToMany(Campus::class, 'campus_asesor', 'persona_id', 'campus_id')
            ->withTimestamps();
    }

    public function aspirantes(): BelongsToMany
    {
        return $this->belongsToMany(Aspirante::class, 'aspirante_asesor', 'persona_id', 'aspirante_id')
            ->withTimestamps();
    }

    /**
     * Los que están en turno.
     *
     * Se pregunta por la CLAVE de la situación y no por su id: los catálogos se
     * resiembran y los ids cambian, la clave es lo que el código conoce.
     */
    public function scopeActivos(Builder $consulta): Builder
    {
        return $consulta->whereHas('situacion', fn (Builder $q) => $q->where('clave', 'activo'));
    }

    /** Los que atienden ese campus. Sin campus asignado, atiende a todos. */
    public function scopeDeCampus(Builder $consulta, ?int $campusId): Builder
    {
        if ($campusId === null) {
            return $consulta;
        }

        /*
         * Un asesor SIN campus marcado atiende cualquiera.
         *
         * Es lo que hace usable la pantalla en una escuela de un solo plantel:
         * obligar a marcar el campus ahí sería pedir un dato que no distingue
         * nada. En una de varios, quien no lo marque entra en todas las ruedas,
         * que es lo que se espera de un coordinador.
         */
        return $consulta->where(fn (Builder $q) => $q
            ->whereHas('campus', fn (Builder $c) => $c->where('campus.id', $campusId))
            ->orWhereDoesntHave('campus'));
    }

    public function estaActivo(): bool
    {
        return $this->situacion?->clave === 'activo';
    }
}
