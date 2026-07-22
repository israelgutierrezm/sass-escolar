<?php

declare(strict_types=1);

namespace App\Models\Finanzas;

use App\Models\Academico\Carrera;
use App\Models\Academico\Oferta;
use App\Models\Academico\PlanEstudio;
use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * planes_cobro (TENANT) — a quién se le cobra qué esquema.
 *
 * `aplica_a_id` es polimórfico y va sin FK, igual que `formulario_asignacion`:
 * apunta a carrera, plan u oferta según el tipo, y no hay una sola tabla a la
 * cual apuntar. Con `aplica_a_tipo = global` el id queda en NULL.
 */
class PlanCobro extends Model
{
    use TieneAuditoria;

    public const APLICA_GLOBAL = 'global';

    public const APLICA_CARRERA = 'carrera';

    public const APLICA_PLAN = 'plan';

    public const APLICA_OFERTA = 'oferta';

    protected $table = 'planes_cobro';

    protected $fillable = [
        'nombre',
        'moneda',
        'aplica_a_tipo',
        'aplica_a_id',
        'vigente_desde',
        'vigente_hasta',
    ];

    protected function casts(): array
    {
        return [
            'vigente_desde' => 'date',
            'vigente_hasta' => 'date',
        ];
    }

    public function reglas(): HasMany
    {
        return $this->hasMany(ReglaGeneracion::class, 'plan_cobro_id');
    }

    /**
     * El destinatario del plan. Se resuelve a mano porque `aplica_a_id` no
     * tiene FK: con `morphTo` habría que guardar el nombre de la clase, y aquí
     * se guarda un tipo de dominio ('carrera', 'plan') que sobrevive a un
     * cambio de namespace.
     */
    public function destinatario(): ?Model
    {
        if ($this->aplica_a_id === null) {
            return null;
        }

        return match ($this->aplica_a_tipo) {
            self::APLICA_CARRERA => Carrera::find($this->aplica_a_id),
            self::APLICA_PLAN => PlanEstudio::find($this->aplica_a_id),
            self::APLICA_OFERTA => Oferta::find($this->aplica_a_id),
            default => null,
        };
    }

    /** Un plan sin fecha de fin sigue vigente: la ausencia es "hasta nuevo aviso". */
    public function scopeVigentes(Builder $query, ?string $fecha = null): Builder
    {
        $fecha ??= now()->toDateString();

        return $query->whereDate('vigente_desde', '<=', $fecha)
            ->where(fn (Builder $q) => $q
                ->whereNull('vigente_hasta')
                ->orWhereDate('vigente_hasta', '>=', $fecha));
    }
}
