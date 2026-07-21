<?php

declare(strict_types=1);

namespace App\Models;

use Stancl\Tenancy\Database\Concerns\HasDatabase;
use Stancl\Tenancy\Database\Concerns\HasDomains;
use Stancl\Tenancy\Database\Models\Tenant as BaseTenant;

/**
 * La escuela como cliente del SaaS (capa LANDLORD).
 *
 * Cada tenant tiene su propia base de datos (multi-database) y uno o más
 * dominios/subdominios. Los datos operativos de la escuela viven en su BD
 * de tenant; aquí, en la BD central (landlord), solo vive el registro del
 * cliente y su configuración de plataforma.
 */
class Tenant extends BaseTenant
{
    use HasDatabase;
    use HasDomains;
}
