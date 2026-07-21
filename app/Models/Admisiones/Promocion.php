<?php

declare(strict_types=1);

namespace App\Models\Admisiones;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** promociones (TENANT) — descuentos de admisión. */
class Promocion extends Model
{
    use TieneAuditoria;

    protected $table = 'promociones';

    protected $fillable = ['clave', 'nombre', 'descripcion', 'descuento', 'vigencia'];

    protected function casts(): array
    {
        return [
            'vigencia' => 'date',
        ];
    }

    public function aspirantes(): BelongsToMany
    {
        return $this->belongsToMany(Aspirante::class, 'aspirante_promocion', 'promocion_id', 'aspirante_id')
            ->withTimestamps();
    }
}
