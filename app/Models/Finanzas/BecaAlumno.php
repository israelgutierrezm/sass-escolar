<?php

declare(strict_types=1);

namespace App\Models\Finanzas;

use App\Models\Admisiones\MatriculaOferta;
use App\Models\Concerns\TieneAuditoria;
use App\Models\Identidad\Persona;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * becas_alumno (TENANT) — la beca concedida a UNA matrícula.
 *
 * Cuelga de `matricula_oferta` y no de la persona: quien cursa dos programas
 * puede tener beca en uno y no en el otro. `autorizado_por` no es opcional por
 * capricho de auditoría — una beca es una decisión con costo y alguien tiene
 * que responder por ella.
 */
class BecaAlumno extends Model
{
    use TieneAuditoria;

    protected $table = 'becas_alumno';

    protected $fillable = [
        'matricula_oferta_id',
        'recargo_descuento_id',
        'vigente_desde',
        'vigente_hasta',
        'autorizado_por',
        'motivo',
    ];

    protected function casts(): array
    {
        return [
            'vigente_desde' => 'date',
            'vigente_hasta' => 'date',
        ];
    }

    public function matriculaOferta(): BelongsTo
    {
        return $this->belongsTo(MatriculaOferta::class, 'matricula_oferta_id');
    }

    public function recargoDescuento(): BelongsTo
    {
        return $this->belongsTo(RecargoDescuento::class, 'recargo_descuento_id');
    }

    public function autorizadaPor(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'autorizado_por');
    }

    /** Sin fecha de fin, la beca sigue corriendo. */
    public function scopeVigentes(Builder $query, ?string $fecha = null): Builder
    {
        $fecha ??= now()->toDateString();

        return $query->whereDate('vigente_desde', '<=', $fecha)
            ->where(fn (Builder $q) => $q
                ->whereNull('vigente_hasta')
                ->orWhereDate('vigente_hasta', '>=', $fecha));
    }
}
