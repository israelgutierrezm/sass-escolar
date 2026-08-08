<?php

declare(strict_types=1);

namespace App\Models\Landlord;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * emision_consumos (LANDLORD) — cada XML que una escuela generó.
 *
 * Se registran TODOS, incluidas las regeneraciones, porque hace falta saber qué
 * se está usando; pero sólo el primero de cada trámite lleva `cobrado`.
 */
class ConsumoEmision extends Model
{
    use CentralConnection;

    public const CERTIFICADO = 'certificado';

    public const TITULO = 'titulo';

    protected $table = 'emision_consumos';

    protected $fillable = ['tenant_id', 'tipo', 'curp', 'plan_clave', 'referencia', 'cobrado'];

    protected function casts(): array
    {
        return ['cobrado' => 'boolean'];
    }

    /**
     * Los renglones del MISMO trámite: misma escuela, mismo tipo, misma persona
     * y mismo plan.
     *
     * Es la consulta que decide si un XML cobra o es una regeneración. La CURP
     * se compara en mayúsculas porque es como se guarda en los documentos
     * oficiales, y una capturada en minúsculas cobraría de nuevo por lo mismo.
     */
    public function scopeDelMismoTramite(
        Builder $query,
        string $tenantId,
        string $tipo,
        string $curp,
        string $planClave,
    ): Builder {
        return $query
            ->where('tenant_id', $tenantId)
            ->where('tipo', $tipo)
            ->whereRaw('UPPER(curp) = ?', [mb_strtoupper(trim($curp))])
            ->where('plan_clave', $planClave);
    }
}
