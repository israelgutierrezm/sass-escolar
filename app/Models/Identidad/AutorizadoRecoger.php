<?php

declare(strict_types=1);

namespace App\Models\Identidad;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * autorizados_recoger (TENANT) — quién puede o NO puede recoger a un alumno.
 *
 * Ver `docs/plan-salida-segura.md`. Una fila AUTORIZA (`permitido=true`, un
 * tercero) o BLOQUEA (`permitido=false`, custodia). Los tutores no viven aquí:
 * se autorizan por su vínculo salvo que un bloqueo lo diga.
 */
class AutorizadoRecoger extends Model
{
    use TieneAuditoria;

    protected $table = 'autorizados_recoger';

    protected $fillable = [
        'alumno_persona_id',
        'persona_id',
        'nombre',
        'identificacion',
        'parentesco_id',
        'foto_ruta',
        'permitido',
        'vigencia_desde',
        'vigencia_hasta',
        'motivo',
    ];

    protected function casts(): array
    {
        return [
            'permitido' => 'boolean',
            'vigencia_desde' => 'date',
            'vigencia_hasta' => 'date',
        ];
    }

    public function alumno(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'alumno_persona_id');
    }

    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'persona_id');
    }

    public function parentesco(): BelongsTo
    {
        return $this->belongsTo(Parentesco::class, 'parentesco_id');
    }

    /** ¿Está dentro de su ventana de vigencia HOY? Sin fechas, siempre. */
    public function vigente(): bool
    {
        $hoy = now()->startOfDay();

        return ($this->vigencia_desde === null || $this->vigencia_desde->lte($hoy))
            && ($this->vigencia_hasta === null || $this->vigencia_hasta->gte($hoy));
    }

    /** @param  Builder<self>  $q */
    public function scopeVigentes(Builder $q): Builder
    {
        $hoy = now()->startOfDay()->toDateString();

        return $q
            ->where(fn (Builder $w) => $w->whereNull('vigencia_desde')->orWhere('vigencia_desde', '<=', $hoy))
            ->where(fn (Builder $w) => $w->whereNull('vigencia_hasta')->orWhere('vigencia_hasta', '>=', $hoy));
    }

    /** @param  Builder<self>  $q */
    public function scopeAutoriza(Builder $q): Builder
    {
        return $q->where('permitido', true);
    }

    /** @param  Builder<self>  $q */
    public function scopeBloquea(Builder $q): Builder
    {
        return $q->where('permitido', false);
    }
}
