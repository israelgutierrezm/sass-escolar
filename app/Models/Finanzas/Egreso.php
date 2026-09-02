<?php

declare(strict_types=1);

namespace App\Models\Finanzas;

use App\Models\Concerns\TieneAuditoria;
use App\Models\ControlEscolar\Ciclo;
use App\Models\RH\PeriodoNomina;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * egresos (TENANT) — dinero que salió.
 *
 * ── La ÚNICA fuente del ejercido ───────────────────────────────────────────
 * La tentación es derivarlo —«la nómina de este campus cuenta contra su centro
 * de costo»— y eso crea una segunda verdad: un número que cambia según de
 * dónde se mire y que nadie puede auditar renglón por renglón. La nómina entra
 * aquí como un egreso más, con su `origen` puesto, y así «ejercido» significa
 * lo mismo siempre.
 *
 * ── Se CORRIGE, no es inmutable ────────────────────────────────────────────
 * Al revés que un CFDI o un acta. Un egreso no es un documento que la escuela
 * emite: es la CAPTURA de algo que pasó en otro lado, y los errores de captura
 * son la norma. Lo que le da autoridad al renglón es su comprobante y su
 * auditoría —quién lo escribió y quién lo cambió—, no que no se pueda tocar.
 */
class Egreso extends Model
{
    use TieneAuditoria;

    public const ORIGEN_CAPTURA = 'captura';

    /** Traído de un periodo de nómina, con un acto deliberado. */
    public const ORIGEN_NOMINA = 'nomina';

    protected $table = 'egresos';

    protected $attributes = ['origen' => self::ORIGEN_CAPTURA];

    protected $fillable = [
        'fecha',
        'centro_costo_id',
        'partida_id',
        'ciclo_id',
        'monto',
        'descripcion',
        'beneficiario',
        'referencia',
        'comprobante_ruta',
        'comprobante_nombre',
        'origen',
        'origen_id',
    ];

    protected function casts(): array
    {
        return ['fecha' => 'date', 'monto' => 'decimal:2'];
    }

    public function centro(): BelongsTo
    {
        return $this->belongsTo(CentroCosto::class, 'centro_costo_id');
    }

    public function partida(): BelongsTo
    {
        return $this->belongsTo(PartidaPresupuesto::class, 'partida_id');
    }

    public function ciclo(): BelongsTo
    {
        return $this->belongsTo(Ciclo::class);
    }

    /** El periodo de nómina del que salió, cuando salió de uno. */
    public function periodoNomina(): BelongsTo
    {
        return $this->belongsTo(PeriodoNomina::class, 'origen_id');
    }

    public function vieneDeNomina(): bool
    {
        return $this->origen === self::ORIGEN_NOMINA;
    }

    public function scopeDelCiclo(Builder $consulta, int $cicloId): Builder
    {
        return $consulta->where('ciclo_id', $cicloId);
    }
}
