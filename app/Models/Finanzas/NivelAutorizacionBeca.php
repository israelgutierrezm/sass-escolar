<?php

declare(strict_types=1);

namespace App\Models\Finanzas;

use App\Models\Concerns\TieneAuditoria;
use App\Models\Identidad\Rol;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * niveles_autorizacion_beca (TENANT-CONFIG) — quién tiene que firmar, y desde
 * cuánto.
 *
 * El umbral se mide sobre lo que la BECA dice —40 %, o 3 000 pesos—, no sobre
 * el dinero que acabará descontando: ese número no existe cuando hay que
 * decidir quién firma. Por eso cada nivel declara su `modo`, y sólo mira las
 * becas de su misma escala.
 */
class NivelAutorizacionBeca extends Model
{
    use TieneAuditoria;

    protected $table = 'niveles_autorizacion_beca';

    protected $fillable = ['nombre', 'rol_id', 'modo', 'desde', 'orden', 'activo'];

    protected function casts(): array
    {
        return ['desde' => 'decimal:4', 'orden' => 'integer', 'activo' => 'boolean'];
    }

    public function rol(): BelongsTo
    {
        return $this->belongsTo(Rol::class, 'rol_id');
    }

    public function scopeActivos(Builder $consulta): Builder
    {
        return $consulta->where('activo', true);
    }

    /** Cómo se lee el umbral en pantalla: «40 %» o «$3,000». */
    public function umbral(): string
    {
        return $this->modo === Beca::MODO_PORCENTAJE
            ? rtrim(rtrim(number_format((float) $this->desde * 100, 2), '0'), '.').' %'
            : '$'.number_format((float) $this->desde, 2);
    }
}
