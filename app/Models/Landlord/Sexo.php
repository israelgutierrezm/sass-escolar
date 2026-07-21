<?php

declare(strict_types=1);

namespace App\Models\Landlord;

use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * sexos (LANDLORD) — catálogo oficial SEP (H/M).
 */
class Sexo extends Model
{
    use CentralConnection;

    protected $table = 'sexos';

    public $timestamps = false;

    protected $fillable = [
        'clave',
        'nombre',
    ];
}
