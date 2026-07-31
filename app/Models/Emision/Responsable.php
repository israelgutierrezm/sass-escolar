<?php

declare(strict_types=1);

namespace App\Models\Emision;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * responsables (TENANT) — la PERSONA que firma certificaciones (tipo 1) y
 * titulaciones (tipo 2). Es única por (tipo, CURP): una persona, un registro.
 * Sus certificados viven en `certificados_responsable`; al renovar se agrega uno
 * nuevo a la MISMA persona (historial), no una persona nueva.
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
    ];

    protected function casts(): array
    {
        return ['activo' => 'boolean'];
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

    public function certificados(): HasMany
    {
        return $this->hasMany(CertificadoResponsable::class)->latest('id');
    }

    /** Bitácora de movimientos (alta, reactivación, desactivación, etc.). */
    public function movimientos(): HasMany
    {
        return $this->hasMany(ResponsableMovimiento::class)->latest('id');
    }

    /** El certificado con el que firma hoy (el vigente). */
    public function certificadoVigente(): HasOne
    {
        return $this->hasOne(CertificadoResponsable::class)->where('vigente', true)->latestOfMany();
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
