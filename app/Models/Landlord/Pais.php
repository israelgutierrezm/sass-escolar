<?php

declare(strict_types=1);

namespace App\Models\Landlord;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * paises (LANDLORD) — catálogo universal read-only para los tenants.
 */
class Pais extends Model
{
    use CentralConnection;

    protected $table = 'paises';

    public $timestamps = false;

    protected $fillable = [
        'clave_iso',
        'nombre',
    ];

    public function entidadesFederativas(): HasMany
    {
        return $this->hasMany(EntidadFederativa::class);
    }
}
