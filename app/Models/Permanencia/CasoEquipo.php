<?php

declare(strict_types=1);

namespace App\Models\Permanencia;

use App\Models\Concerns\TieneAuditoria;
use App\Models\Identidad\Persona;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * caso_equipo (TENANT) — quién más participa.
 *
 * ── Estar en el equipo NO es lo que da acceso ──────────────────────────────
 * Eso lo siguen decidiendo el permiso y el campus. Esta tabla dice quién
 * PARTICIPA, no quién puede mirar: confundirlo convertiría una lista de trabajo
 * en un mecanismo de autorización paralelo, y entonces agregar a alguien al
 * equipo sería una forma de concederle permisos sin pasar por los roles.
 *
 * Lo que sí decide es la visibilidad `equipo` de una intervención, que es otra
 * cosa: ahí no se pregunta «¿puede entrar?» sino «¿esto es suyo?».
 */
class CasoEquipo extends Model
{
    use TieneAuditoria;

    protected $table = 'caso_equipo';

    protected $fillable = ['caso_id', 'persona_id', 'papel', 'desde', 'hasta'];

    protected function casts(): array
    {
        return ['desde' => 'date', 'hasta' => 'date'];
    }

    public function caso(): BelongsTo
    {
        return $this->belongsTo(CasoPermanencia::class, 'caso_id');
    }

    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'persona_id');
    }

    /** Quien sigue en el equipo hoy: sin fecha de salida o con ella por delante. */
    public function scopeVigentes(Builder $c, ?string $dia = null): Builder
    {
        $fecha = $dia ?? now()->toDateString();

        return $c->whereDate('desde', '<=', $fecha)
            ->where(fn (Builder $q) => $q->whereNull('hasta')->orWhereDate('hasta', '>=', $fecha));
    }
}
