<?php

declare(strict_types=1);

namespace App\Models\Nomina;

use App\Models\Academico\Campus;
use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * adscripciones (TENANT) — qué puesto ocupa un expediente, dónde y desde cuándo.
 *
 * ── No duplica `persona_rol.campus_id` ────────────────────────────────────
 * Aquél acota lo que un usuario PUEDE VER; ésta dice qué puesto ocupa en el
 * organigrama, con su historia. Alguien puede tener permisos globales y estar
 * adscrito a un solo campus, y al revés.
 *
 * ── Se cierra, no se borra ────────────────────────────────────────────────
 * Un cambio de puesto pone `vigente_hasta` a la vieja y abre otra. Borrar la
 * anterior perdería desde cuándo ocupó cada cosa, que es la mitad de para qué
 * existe esta tabla.
 */
class Adscripcion extends Model
{
    use TieneAuditoria;

    protected $table = 'adscripciones';

    protected $fillable = [
        'expediente_laboral_id',
        'puesto_id',
        'campus_id',
        'vigente_desde',
        'vigente_hasta',
        'es_principal',
    ];

    protected function casts(): array
    {
        return [
            'vigente_desde' => 'date',
            'vigente_hasta' => 'date',
            'es_principal' => 'boolean',
        ];
    }

    public function expediente(): BelongsTo
    {
        return $this->belongsTo(ExpedienteLaboral::class, 'expediente_laboral_id');
    }

    public function puesto(): BelongsTo
    {
        return $this->belongsTo(Puesto::class, 'puesto_id');
    }

    public function campus(): BelongsTo
    {
        return $this->belongsTo(Campus::class, 'campus_id');
    }

    /** Sin fecha de fin, o con una que todavía no llega. */
    public function estaVigente(): bool
    {
        return $this->vigente_hasta === null || ! $this->vigente_hasta->lt(now()->startOfDay());
    }

    public function scopeVigentes(Builder $consulta): Builder
    {
        return $consulta->where(fn (Builder $q) => $q
            ->whereNull('vigente_hasta')
            ->orWhereDate('vigente_hasta', '>=', now()->toDateString()));
    }
}
