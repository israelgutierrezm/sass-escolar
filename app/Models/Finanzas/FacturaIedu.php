<?php

declare(strict_types=1);

namespace App\Models\Finanzas;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * factura_iedu (TENANT) — el complemento «Instituciones Educativas Privadas».
 *
 * Es lo que hace deducible una colegiatura. Sin él la factura es válida y se
 * timbra sin un solo error, pero el padre no puede deducirla.
 *
 * ── Se COPIA, no se consulta ───────────────────────────────────────────────
 * Los cuatro datos salen de la alumna y de su plan, y aquí quedan congelados
 * como el emisor y el receptor: corregir mañana el RVOE del plan no puede
 * cambiar lo que dice un comprobante ya timbrado.
 *
 * ── Lo que NO lleva ────────────────────────────────────────────────────────
 * El `rfcPago` del SAT —«el RFC de quien paga cuando es distinto del
 * receptor»— se queda fuera: el sistema no registra quién entregó físicamente
 * el dinero, sólo a nombre de quién se factura. Ponerlo obligaría a suponerlo,
 * y un dato supuesto en un comprobante fiscal es peor que uno ausente: el
 * atributo es opcional para el SAT.
 */
class FacturaIedu extends Model
{
    use TieneAuditoria;

    protected $table = 'factura_iedu';

    protected $fillable = [
        'factura_id',
        'nombre_alumno',
        'curp',
        'nivel_educativo',
        'aut_rvoe',
    ];

    public function factura(): BelongsTo
    {
        return $this->belongsTo(Factura::class, 'factura_id');
    }
}
