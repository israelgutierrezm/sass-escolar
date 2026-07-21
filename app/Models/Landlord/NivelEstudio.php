<?php

declare(strict_types=1);

namespace App\Models\Landlord;

use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * niveles_estudio (LANDLORD) — niveles estandarizados por la SEP.
 * `orden` define la progresión académica.
 */
class NivelEstudio extends Model
{
    use CentralConnection;

    protected $table = 'niveles_estudio';

    public $timestamps = false;

    protected $fillable = [
        'clave',
        'nombre',
        'orden',
    ];
}
