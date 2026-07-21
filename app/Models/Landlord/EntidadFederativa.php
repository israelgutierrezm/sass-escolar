<?php

declare(strict_types=1);

namespace App\Models\Landlord;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * entidades_federativas (LANDLORD) — 32 entidades de México + NE (extranjero).
 * `clave` = código de dos letras RENAPO/CURP.
 */
class EntidadFederativa extends Model
{
    use CentralConnection;

    protected $table = 'entidades_federativas';

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
