<?php

declare(strict_types=1);

namespace App\Models\Emision;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * responsables (TENANT) — quien firma certificaciones (tipo 1) y titulaciones
 * (tipo 2). Su identidad se lee del `.cer`; se completa con cargo y título.
 */
class Responsable extends Model
{
    use TieneAuditoria;

    protected $table = 'responsables';

    protected $fillable = [
        'tipo_responsable_id',
        'nombre',
        'apellido_paterno',
        'apellido_materno',
        'curp',
        'cargo_id',
        'titulo_profesional_id',
        'activo',
        'cer_titular',
        'cer_serial',
        'cer_vigencia_inicio',
        'cer_vigencia_fin',
        'cer_pem',
    ];

    protected function casts(): array
    {
        return [
            'cer_vigencia_inicio' => 'date',
            'cer_vigencia_fin' => 'date',
            'activo' => 'boolean',
        ];
    }

    public function tipoResponsable(): BelongsTo
    {
        return $this->belongsTo(TipoResponsable::class);
    }

    public function cargo(): BelongsTo
    {
        return $this->belongsTo(Cargo::class);
    }

    public function tituloProfesional(): BelongsTo
    {
        return $this->belongsTo(TituloProfesional::class);
    }

    /** @param  \Illuminate\Database\Eloquent\Builder<Responsable>  $query */
    public function scopeDeTipo($query, int $tipoId)
    {
        return $query->where('tipo_responsable_id', $tipoId);
    }

    /** @param  \Illuminate\Database\Eloquent\Builder<Responsable>  $query */
    public function scopeActivos($query)
    {
        return $query->where('activo', true);
    }

    public function nombreCompleto(): string
    {
        return trim("{$this->nombre} {$this->apellido_paterno} {$this->apellido_materno}");
    }
}
