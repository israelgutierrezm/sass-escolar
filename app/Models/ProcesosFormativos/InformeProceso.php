<?php

declare(strict_types=1);

namespace App\Models\ProcesosFormativos;

use App\Models\Concerns\TieneAuditoria;
use App\Models\Identidad\Usuario;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Un informe del expediente, con su fecha límite.
 *
 * ── La fila EXISTE antes de que se entregue ───────────────────────────────
 * Se crean todas al asignar, con sus fechas calculadas: así el alumno ve desde
 * el primer día cuántos le tocan y para cuándo. Creándolas al entregar, «te
 * falta el segundo parcial» no se podría decir —no habría fila que nombrar— y
 * la fecha límite no existiría hasta después de vencer.
 */
class InformeProceso extends Model
{
    use SoftDeletes, TieneAuditoria;

    /** Todavía no lo entrega. */
    public const PENDIENTE = 'pendiente';

    /** Entregado y sin revisar. */
    public const ENTREGADO = 'entregado';

    /** Revisado y aceptado. */
    public const ACEPTADO = 'aceptado';

    /** Devuelto con retroalimentación: hay que rehacerlo. */
    public const RECHAZADO = 'rechazado';

    /** @var array<string, string> */
    public const ESTADOS = [
        self::PENDIENTE => 'Pendiente',
        self::ENTREGADO => 'Entregado',
        self::ACEPTADO => 'Aceptado',
        self::RECHAZADO => 'Devuelto',
    ];

    protected $table = 'informes_proceso';

    // El estado y la revisión los escribe el servicio: revisar es un acto con
    // permiso, y por un formulario el alumno se aceptaría sus propios informes.
    protected $fillable = [
        'expediente_id', 'tipo_informe_id', 'numero', 'fecha_limite',
        'archivo_ruta', 'nombre_original',
    ];

    protected function casts(): array
    {
        return [
            'fecha_limite' => 'date',
            'entregado_en' => 'datetime',
            'revisado_en' => 'datetime',
        ];
    }

    public function expediente(): BelongsTo
    {
        return $this->belongsTo(ExpedienteProceso::class, 'expediente_id');
    }

    public function tipo(): BelongsTo
    {
        return $this->belongsTo(TipoInformeProceso::class, 'tipo_informe_id');
    }

    public function revisadoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'revisado_por');
    }

    public function scopePendientes(Builder $q): Builder
    {
        return $q->whereIn('estado', [self::PENDIENTE, self::RECHAZADO]);
    }

    public function scopePorRevisar(Builder $q): Builder
    {
        return $q->where('estado', self::ENTREGADO);
    }

    /**
     * ¿Cierra el proceso? Lo dice la BANDERA del catálogo, no la clave.
     *
     * Es la misma lección de `entra_a_nomina` y `cuenta_como_egresado`:
     * preguntar por `clave === 'final'` funciona hoy y deja de funcionar en
     * silencio el día que la escuela edite su catálogo.
     */
    public function esFinal(): bool
    {
        return $this->tipo?->es_final === true;
    }

    /** Entregado fuera de plazo. Se anota, no se rechaza solo. */
    public function llegoTarde(): bool
    {
        return $this->fecha_limite !== null
            && $this->entregado_en !== null
            && $this->entregado_en->toDateString() > $this->fecha_limite->toDateString();
    }

    /** Se pasó la fecha y sigue sin entregarse. */
    public function estaVencido(?string $dia = null): bool
    {
        return $this->fecha_limite !== null
            && $this->entregado_en === null
            && $this->fecha_limite->toDateString() < ($dia ?? now()->toDateString());
    }

    public function etiquetaEstado(): string
    {
        return self::ESTADOS[$this->estado] ?? $this->estado;
    }
}
