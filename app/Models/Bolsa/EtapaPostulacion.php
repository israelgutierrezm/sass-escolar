<?php

declare(strict_types=1);

namespace App\Models\Bolsa;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * etapas_postulacion (TENANT-CONFIG) — por dónde va una postulación.
 *
 * Lleva `orden` porque es un recorrido: la secuencia es lo que permite medir en
 * qué etapa se atoran los postulantes.
 *
 * ── Dos banderas, no un enum ──────────────────────────────────────────────
 * `marca_colocacion` y `es_final` son hechos INDEPENDIENTES: «Rechazado» cierra
 * el proceso y no coloca a nadie, «Contratado» hace las dos cosas. Y viven en el
 * catálogo y no en el código porque la escuela puede renombrar sus etapas o
 * agregar las suyas; preguntar por `clave = 'contratado'` funciona hoy y deja de
 * funcionar en silencio el día que alguien edite el catálogo.
 */
class EtapaPostulacion extends Model
{
    use TieneAuditoria;

    protected $table = 'etapas_postulacion';

    protected $fillable = ['clave', 'nombre', 'orden', 'activo', 'marca_colocacion', 'es_final'];

    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
            'marca_colocacion' => 'boolean',
            'es_final' => 'boolean',
        ];
    }

    public function scopeActivos(Builder $consulta): Builder
    {
        return $consulta->where('activo', true)->orderBy('orden')->orderBy('nombre');
    }

    /** La primera del recorrido: con la que nace toda postulación. */
    public static function inicial(): ?self
    {
        return self::query()->activos()->first();
    }

    /** Las que declaran que a esa persona la contrataron. */
    public function scopeQueColocan(Builder $consulta): Builder
    {
        return $consulta->where('marca_colocacion', true);
    }

    /** Las que cierran el proceso: ni contratado ni rechazado siguen abiertos. */
    public function scopeFinales(Builder $consulta): Builder
    {
        return $consulta->where('es_final', true);
    }
}
