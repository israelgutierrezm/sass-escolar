<?php

declare(strict_types=1);

namespace App\Models\Finanzas;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Concerns\AsPivot;

/**
 * pago_adeudo (TENANT) — la aplicación de un pago a un adeudo.
 *
 * Es pivote con dato propio: `monto_aplicado` es lo que hace posible el pago
 * parcial y el split. Sin esa columna, un pago solo podría cubrir adeudos
 * completos, que no es como se cobra en una escuela.
 *
 * PK compuesta (pago_id, adeudo_id): el mismo pago no se aplica dos veces al
 * mismo adeudo — se corrige el monto de la fila que ya existe.
 */
class PagoAdeudo extends Model
{
    use AsPivot;
    use TieneAuditoria;

    protected $table = 'pago_adeudo';

    public $incrementing = false;

    protected $fillable = ['pago_id', 'adeudo_id', 'monto_aplicado'];

    protected function casts(): array
    {
        return ['monto_aplicado' => 'decimal:2'];
    }
}
