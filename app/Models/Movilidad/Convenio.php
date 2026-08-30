<?php

declare(strict_types=1);

namespace App\Models\Movilidad;

use App\Models\Academico\ProgramaAcademico;
use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * convenios (TENANT) — el acuerdo firmado con una institución aliada.
 *
 * ── Sin programas académicos señaladas, cubre TODAS ───────────────────────────────────
 * La mayoría de los convenios marco son generales, y exigir al menos una
 * obligaría a palomear las veinte programas académicos cada vez. `scopeParaProgramaAcademico` incluye
 * los que no señalan ninguna, y la pantalla lo dice con palabras: un hueco se
 * lee como captura incompleta.
 *
 * ── Vencido ≠ suspendido ──────────────────────────────────────────────────
 * `estaVencido()` sale de la fecha; la situación dice si la escuela lo tiene
 * suspendido o en firma. `scopeVigentes` cruza las dos, igual que
 * `Vacante::scopeVigentes`: sin la fecha, un convenio caducado seguiría
 * amparando convocatorias nuevas.
 */
class Convenio extends Model
{
    use TieneAuditoria;

    protected $table = 'convenios';

    protected $fillable = [
        'institucion_aliada_id',
        'tipo_convenio_id',
        'folio',
        'vigente_desde',
        'vigente_hasta',
        'situacion_id',
        'documento_ruta',
        'notas',
    ];

    protected function casts(): array
    {
        return ['vigente_desde' => 'date', 'vigente_hasta' => 'date'];
    }

    public function institucion(): BelongsTo
    {
        return $this->belongsTo(InstitucionAliada::class, 'institucion_aliada_id');
    }

    public function tipo(): BelongsTo
    {
        return $this->belongsTo(TipoConvenio::class, 'tipo_convenio_id');
    }

    public function situacion(): BelongsTo
    {
        return $this->belongsTo(SituacionConvenio::class, 'situacion_id');
    }

    public function programasAcademicos(): BelongsToMany
    {
        return $this->belongsToMany(ProgramaAcademico::class, 'convenio_programas_academicos', 'convenio_id', 'programa_academico_id')
            ->wherePivotNull('deleted_at')
            ->withTimestamps();
    }

    public function convocatorias(): HasMany
    {
        return $this->hasMany(ConvocatoriaMovilidad::class, 'convenio_id');
    }

    /** ¿Se le pasó la fecha? Es otra pregunta que «está suspendido». */
    public function estaVencido(): bool
    {
        return $this->vigente_hasta !== null && $this->vigente_hasta->lt(now()->startOfDay());
    }

    /**
     * Los que hoy pueden amparar una convocatoria.
     *
     * Las dos condiciones hacen falta: la situación dice si la escuela lo tiene
     * activo, la fecha dice si sigue vivo. Sin la segunda, un convenio caducado
     * seguiría abriendo convocatorias.
     */
    public function scopeVigentes(Builder $consulta): Builder
    {
        return $consulta
            ->whereHas('situacion', fn (Builder $q) => $q->where('permite_convocar', true))
            ->whereDate('vigente_desde', '<=', now()->toDateString())
            ->where(fn (Builder $q) => $q
                ->whereNull('vigente_hasta')
                ->orWhereDate('vigente_hasta', '>=', now()->toDateString()));
    }

    /** Los que aplican a un programa académico. Sin programas académicos señaladas = todas. */
    public function scopeParaProgramaAcademico(Builder $consulta, ?int $programaAcademicoId): Builder
    {
        return $consulta->where(fn (Builder $q) => $q
            ->whereDoesntHave('programasAcademicos')
            ->when($programaAcademicoId !== null, fn (Builder $c) => $c->orWhereHas(
                'programasAcademicos',
                fn (Builder $cc) => $cc->where('programas_academicos.id', $programaAcademicoId),
            )));
    }
}
