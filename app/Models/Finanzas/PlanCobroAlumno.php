<?php

declare(strict_types=1);

namespace App\Models\Finanzas;

use App\Models\Admisiones\MatriculaOferta;
use App\Models\Concerns\TieneAuditoria;
use App\Models\Identidad\Usuario;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * plan_cobro_alumno (TENANT) — el plan que se le vinculó a un alumno.
 *
 * Vincular es lo que dispara la generación masiva de sus cargos: no depende de
 * que el alumno ya esté inscrito al ciclo, porque en muchas escuelas se cobra
 * antes de inscribir (de hecho pagar suele ser el requisito para inscribirse).
 */
class PlanCobroAlumno extends Model
{
    use TieneAuditoria;

    public const ACTIVO = 'activo';

    public const CANCELADO = 'cancelado';

    protected $table = 'plan_cobro_alumno';

    protected $fillable = [
        'plan_cobro_id',
        'matricula_oferta_id',
        'estatus',
        'asignado_en',
        'asignado_por',
    ];

    protected function casts(): array
    {
        return ['asignado_en' => 'datetime'];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(PlanCobro::class, 'plan_cobro_id');
    }

    public function matricula(): BelongsTo
    {
        return $this->belongsTo(MatriculaOferta::class, 'matricula_oferta_id');
    }

    public function asignador(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'asignado_por');
    }

    public function scopeActivos(Builder $query): Builder
    {
        return $query->where('estatus', self::ACTIVO);
    }
}
