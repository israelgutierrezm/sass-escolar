<?php

declare(strict_types=1);

namespace App\Models\Admisiones;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** descuentos de admisión (TENANT) — descuentos de admisión. */
class Captacion extends Model
{
    use TieneAuditoria;

    protected $table = 'descuentos_admision';

    protected $fillable = ['clave', 'nombre', 'descripcion', 'descuento', 'vigencia'];

    protected function casts(): array
    {
        return [
            'vigencia' => 'date',
        ];
    }

    public function aspirantes(): BelongsToMany
    {
        return $this->belongsToMany(Aspirante::class, 'aspirante_descuento_admision', 'descuento_admision_id', 'aspirante_id')
            ->withTimestamps();
    }
}
