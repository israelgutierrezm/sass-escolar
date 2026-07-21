<?php

declare(strict_types=1);

namespace App\Models\Landlord;

use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * generos (LANDLORD) — identidad de género, separada del sexo biológico.
 */
class Genero extends Model
{
    use CentralConnection;

    protected $table = 'generos';

    public $timestamps = false;

    protected $fillable = [
        'clave',
        'nombre',
    ];
}
