<?php

declare(strict_types=1);

namespace App\Models\Movilidad;

use App\Models\Concerns\TieneAuditoria;
use App\Models\Landlord\Pais;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * instituciones_aliadas (TENANT) — con quién se tienen convenios.
 *
 * `pais_id` apunta a `paises`, que vive en la base CENTRAL: se guarda el id SIN
 * foránea, que es la regla del proyecto para los catálogos universales. La
 * relación sí resuelve porque el modelo landlord usa `CentralConnection`.
 *
 * Se APAGA con `activa`, no se borra: sus convenios y las estancias que
 * ampararon son historia académica de alguien.
 */
class InstitucionAliada extends Model
{
    use TieneAuditoria;

    protected $table = 'instituciones_aliadas';

    protected $fillable = ['nombre', 'pais_id', 'ciudad', 'tipo_id', 'sitio_web', 'activa'];

    protected function casts(): array
    {
        return ['activa' => 'boolean'];
    }

    public function pais(): BelongsTo
    {
        return $this->belongsTo(Pais::class, 'pais_id');
    }

    public function tipo(): BelongsTo
    {
        return $this->belongsTo(TipoInstitucion::class, 'tipo_id');
    }

    public function convenios(): HasMany
    {
        return $this->hasMany(Convenio::class, 'institucion_aliada_id');
    }

    public function scopeActivas(Builder $consulta): Builder
    {
        return $consulta->where('activa', true);
    }
}
