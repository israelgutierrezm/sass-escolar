<?php

declare(strict_types=1);

namespace App\Models\Finanzas;

use App\Models\Admisiones\MatriculaOferta;
use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * facturas (TENANT) — el CFDI 4.0 de lo cobrado.
 *
 * Inmutable por regulación: una factura timbrada no se edita. Corregirla es
 * cancelarla y emitir otra, que queda ligada por `factura_sustituye_id`.
 *
 * La máquina de estados va como varchar con constantes, no como catálogo
 * TENANT-CONFIG: sus valores los define el SAT y el código, no algo que una
 * escuela deba renombrar. Mismo criterio que `actas.situacion` y
 * `adeudos.estatus`.
 */
class Factura extends Model
{
    use TieneAuditoria;

    /** Capturada, todavía sin mandar al PAC. Es lo único editable o borrable. */
    public const ESTATUS_BORRADOR = 'borrador';

    /** En la cola, esperando al PAC. No se toca desde ninguna pantalla. */
    public const ESTATUS_TIMBRANDO = 'timbrando';

    /** Con UUID. Ya es un documento fiscal. */
    public const ESTATUS_TIMBRADA = 'timbrada';

    /** El PAC la rechazó. Se puede corregir y reintentar: nunca tuvo UUID. */
    public const ESTATUS_ERROR = 'error';

    public const ESTATUS_CANCELADA = 'cancelada';

    /** Motivos de cancelación del SAT. */
    public const MOTIVO_CON_RELACION = '01';   // se emitió con errores, hay sustituta

    public const MOTIVO_SIN_RELACION = '02';   // se emitió con errores, sin sustituta

    public const MOTIVO_NO_LLEVO_ACABO = '03'; // la operación no se realizó

    public const MOTIVO_NOMINATIVA = '04';     // operación nominativa global

    protected $table = 'facturas';

    /** Los mismos defaults que la migración; ver la nota en `Adeudo`. */
    protected $attributes = [
        'estatus' => self::ESTATUS_BORRADOR,
        'metodo_pago_sat' => 'PUE',
        'moneda' => 'MXN',
        'iva' => 0,
        'intentos' => 0,
    ];

    protected $fillable = [
        'matricula_oferta_id',
        'receptor_rfc',
        'receptor_razon_social',
        'receptor_uso_cfdi',
        'receptor_regimen_fiscal',
        'receptor_cp',
        'forma_pago_sat',
        'metodo_pago_sat',
        'moneda',
        'subtotal',
        'iva',
        'total',
        'uuid',
        'pac',
        'estatus',
        'xml_ruta',
        'pdf_ruta',
        'fecha_timbrado',
        'intentos',
        'ultimo_error',
        'cancelada_en',
        'motivo_cancelacion',
        'factura_sustituye_id',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'iva' => 'decimal:2',
            'total' => 'decimal:2',
            'fecha_timbrado' => 'datetime',
            'cancelada_en' => 'datetime',
        ];
    }

    public function matriculaOferta(): BelongsTo
    {
        return $this->belongsTo(MatriculaOferta::class, 'matricula_oferta_id');
    }

    public function conceptos(): HasMany
    {
        return $this->hasMany(FacturaConcepto::class, 'factura_id');
    }

    /** La factura que ésta vino a sustituir (cancelación con relación 01). */
    public function sustituye(): BelongsTo
    {
        return $this->belongsTo(self::class, 'factura_sustituye_id');
    }

    public function sustituida(): HasMany
    {
        return $this->hasMany(self::class, 'factura_sustituye_id');
    }

    /**
     * Con UUID ya es documento fiscal: nada de su contenido se toca.
     *
     * Se pregunta por el UUID y no por el estatus a propósito — una cancelada
     * también lo tiene y tampoco es editable.
     */
    public function esFiscal(): bool
    {
        return $this->uuid !== null;
    }

    /** Solo un borrador o un intento fallido se pueden corregir o borrar. */
    public function esEditable(): bool
    {
        return ! $this->esFiscal()
            && in_array($this->estatus, [self::ESTATUS_BORRADOR, self::ESTATUS_ERROR], true);
    }

    public function estaVigente(): bool
    {
        return $this->estatus === self::ESTATUS_TIMBRADA;
    }

    /** Facturas que "ocupan" un pago: las vivas. Una cancelada lo libera. */
    public function scopeVivas(Builder $query): Builder
    {
        return $query->whereIn('estatus', [
            self::ESTATUS_BORRADOR,
            self::ESTATUS_TIMBRANDO,
            self::ESTATUS_TIMBRADA,
        ]);
    }

    public function scopeTimbradas(Builder $query): Builder
    {
        return $query->where('estatus', self::ESTATUS_TIMBRADA);
    }
}
