<?php

declare(strict_types=1);

namespace App\Models\Admisiones;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * documentos_normativos (TENANT-CONFIG) — documento aceptable y versionado.
 */
class DocumentoNormativo extends Model
{
    use TieneAuditoria;

    protected $table = 'documentos_normativos';

    protected $fillable = [
        'clave',
        'titulo',
        'version',
        'contenido',
        'ruta',
        'vigente_desde',
        'vigente_hasta',
        'obligatorio',
    ];

    protected function casts(): array
    {
        return [
            'vigente_desde' => 'date',
            'vigente_hasta' => 'date',
            'obligatorio' => 'boolean',
        ];
    }

    public function aceptaciones(): HasMany
    {
        return $this->hasMany(Aceptacion::class, 'documento_normativo_id');
    }

    /** Documentos en vigor a una fecha dada (por defecto, hoy). */
    public function scopeVigentes(Builder $query, ?string $fecha = null): Builder
    {
        $fecha ??= now()->toDateString();

        return $query->where('vigente_desde', '<=', $fecha)
            ->where(fn ($q) => $q->whereNull('vigente_hasta')->orWhere('vigente_hasta', '>=', $fecha));
    }
}
