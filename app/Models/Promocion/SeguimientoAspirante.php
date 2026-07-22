<?php

declare(strict_types=1);

namespace App\Models\Promocion;

use App\Models\Admisiones\Aspirante;
use App\Models\Admisiones\EtapaCrm;
use App\Models\Concerns\TieneAuditoria;
use App\Models\Identidad\Persona;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * seguimientos_aspirante (TENANT) — la bitácora de contacto.
 *
 * Es lo que convierte una lista de nombres en un CRM. Append-only en la
 * práctica: un contacto ocurrió, y corregir la nota después no cambia que
 * ocurrió; por eso no hay pantalla de edición.
 *
 * `etapa_crm_id` se congela al registrarlo, no se lee en vivo: es lo que
 * permite medir cuánto tardó un prospecto en pasar de una etapa a la siguiente.
 */
class SeguimientoAspirante extends Model
{
    use TieneAuditoria;

    protected $table = 'seguimientos_aspirante';

    protected $fillable = [
        'aspirante_id',
        'tipo_id',
        'persona_id',
        'etapa_crm_id',
        'nota',
        'proximo_contacto',
        'momento',
    ];

    protected function casts(): array
    {
        return [
            'proximo_contacto' => 'date',
            'momento' => 'datetime',
        ];
    }

    public function aspirante(): BelongsTo
    {
        return $this->belongsTo(Aspirante::class);
    }

    public function tipo(): BelongsTo
    {
        return $this->belongsTo(TipoSeguimiento::class, 'tipo_id');
    }

    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class);
    }

    public function etapa(): BelongsTo
    {
        return $this->belongsTo(EtapaCrm::class, 'etapa_crm_id');
    }

    /** Lo que ya venció o vence hoy: el tablero de "qué me toca". */
    public function scopePendientes(Builder $query, ?string $fecha = null): Builder
    {
        return $query->whereNotNull('proximo_contacto')
            ->whereDate('proximo_contacto', '<=', $fecha ?? now()->toDateString());
    }
}
