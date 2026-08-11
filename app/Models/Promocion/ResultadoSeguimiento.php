<?php

declare(strict_types=1);

namespace App\Models\Promocion;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * resultados_seguimiento (TENANT-CONFIG) — cómo terminó un contacto.
 *
 * Es catálogo y no enum porque de aquí salen los reportes que le importan a
 * promoción —«cuántos no contestan», «cuántos piden informes y no vuelven»— y
 * porque cada escuela nombra distinto sus desenlaces.
 *
 * Dos banderas hacen el trabajo:
 *
 * - `cuenta_como_contacto` separa hablar con la persona de marcarle sin éxito.
 *   Sin ella, «se le llamó seis veces» no dice si alguien lo atendió alguna, y
 *   ésa es justo la diferencia entre un prospecto trabajado y uno abandonado.
 * - `cierra_el_embudo` marca los desenlaces que dan por perdido al prospecto,
 *   para que la pantalla ofrezca moverlo de etapa en el mismo gesto en vez de
 *   obligar a dos capturas que la gente separa y olvida.
 */
class ResultadoSeguimiento extends Model
{
    use TieneAuditoria;

    protected $table = 'resultados_seguimiento';

    protected $fillable = [
        'clave',
        'nombre',
        'cuenta_como_contacto',
        'cierra_el_embudo',
        'orden',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'cuenta_como_contacto' => 'boolean',
            'cierra_el_embudo' => 'boolean',
            'activo' => 'boolean',
        ];
    }

    public function scopeActivos(Builder $consulta): Builder
    {
        return $consulta->where('activo', true);
    }
}
