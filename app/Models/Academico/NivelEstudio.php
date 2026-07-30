<?php

declare(strict_types=1);

namespace App\Models\Academico;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;

/**
 * niveles_estudio (TENANT-CONFIG) — los niveles que la escuela oferta
 * (bachillerato, licenciatura, maestría…). `orden` define la progresión.
 *
 * Pasó de landlord a tenant: cada escuela administra los suyos. Gemelo por
 * estructura del antiguo `App\Models\Landlord\NivelEstudio`, que se conserva
 * solo como semilla del catálogo tenant.
 */
class NivelEstudio extends Model
{
    use TieneAuditoria;

    protected $table = 'niveles_estudio';

    protected $fillable = [
        'clave',
        'identificador',
        'nombre',
        'orden',
        // ClaveProdServ del SAT para el CFDI de colegiaturas: la asigna el SAT
        // por nivel, no por carrera.
        'clave_sat',
    ];
}
