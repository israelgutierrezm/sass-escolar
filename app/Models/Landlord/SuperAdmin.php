<?php

declare(strict_types=1);

namespace App\Models\Landlord;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * Usuario de la casa (LANDLORD): administra todos los tenants desde la app
 * central. No pertenece a ninguna escuela.
 *
 * Fijado a la conexión central vía CentralConnection, de modo que se resuelve
 * contra la BD landlord incluso cuando hay un tenant inicializado.
 */
class SuperAdmin extends Authenticatable
{
    use Notifiable;
    use CentralConnection;

    protected $fillable = [
        'nombre',
        'email',
        'password',
        'rol',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
        ];
    }
}
