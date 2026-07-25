<?php

declare(strict_types=1);

namespace App\Models\Landlord;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * identidades_federativas (LANDLORD) — el catálogo federativo para PERSONAS:
 * el lugar de nacimiento. 32 entidades de México + NE = «Nacido en el
 * extranjero». `clave` = código de dos letras RENAPO/CURP.
 *
 * Gemelo de `EntidadFederativa`, que es el mismo catálogo pero para LUGARES
 * (dónde está un campus); comparten estructura y claves, difieren en el texto
 * del registro 33 porque un plantel no «nace».
 */
class IdentidadFederativa extends Model
{
    use CentralConnection;

    protected $table = 'identidades_federativas';

    public $timestamps = false;

    protected $fillable = [
        'pais_id',
        'clave',
        'nombre',
    ];

    public function pais(): BelongsTo
    {
        return $this->belongsTo(Pais::class);
    }
}
