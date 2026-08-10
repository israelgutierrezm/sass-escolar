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
    use CentralConnection;
    use Notifiable;

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

    /**
     * Quién puede dar por buena la compra de créditos de una escuela.
     *
     * ── Por qué no lo hace la escuela ──────────────────────────────────────
     * La escuela es la que paga. Dejarle validar su propio comprobante sería
     * dejarle regalarse créditos, así que esto vive en la organización y por eso
     * cuelga de `super_admins` y no de los usuarios del tenant —que ni siquiera
     * están en esta base—.
     *
     * ── Y por qué no lo puede todo el mundo aquí ───────────────────────────
     * `comercial` habla con las escuelas y `soporte` las ayuda; ninguno de los
     * dos tiene por qué mover el saldo. Acreditar créditos es cobrar, y se
     * reserva a quien lleva las cuentas.
     */
    public function puedeValidarCreditos(): bool
    {
        return in_array($this->rol, ['superadmin', 'finanzas'], true);
    }
}
