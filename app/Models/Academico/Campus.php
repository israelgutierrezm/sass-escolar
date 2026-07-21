<?php

declare(strict_types=1);

namespace App\Models\Academico;

use App\Models\Concerns\TieneAuditoria;
use App\Models\Landlord\EntidadFederativa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * campus (TENANT). `entidad` resuelve cross-DB contra la landlord.
 */
class Campus extends Model
{
    use TieneAuditoria;

    protected $table = 'campus';

    protected $fillable = [
        'clave',
        'nombre',
        'tipo_campus_id',
        'online',
        'entidad_id',
    ];

    protected function casts(): array
    {
        return [
            'online' => 'boolean',
        ];
    }

    public function tipoCampus(): BelongsTo
    {
        return $this->belongsTo(TipoCampus::class);
    }

    public function entidad(): BelongsTo
    {
        return $this->belongsTo(EntidadFederativa::class, 'entidad_id');
    }

    /** Oferta que se imparte en este campus. */
    public function ofertas(): HasMany
    {
        return $this->hasMany(Oferta::class, 'campus_id');
    }
}
