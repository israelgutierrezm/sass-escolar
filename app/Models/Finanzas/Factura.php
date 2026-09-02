<?php

declare(strict_types=1);

namespace App\Models\Finanzas;

use App\Models\Admisiones\MatriculaOferta;
use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

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

    /**
     * Tipo de comprobante del SAT.
     *
     * La de INGRESO es la factura de siempre: dice cuánto cobró la escuela. La
     * de EGRESO es la nota de crédito, que reduce lo cobrado en una anterior
     * sin cancelarla — el instrumento para una beca autorizada tarde o un cobro
     * de más, y el único que queda cuando ya venció el plazo para cancelar.
     */
    public const TIPO_INGRESO = 'I';

    public const TIPO_EGRESO = 'E';

    /**
     * Relación 01 del SAT: «nota de crédito de los documentos relacionados».
     *
     * Es OTRA relación que la sustitución (04), y por eso la nota de crédito
     * apunta con su propia columna. Ver la migración.
     */
    public const RELACION_NOTA_CREDITO = '01';

    /** Motivos de cancelación del SAT. */
    public const MOTIVO_CON_RELACION = '01';   // se emitió con errores, hay sustituta

    public const MOTIVO_SIN_RELACION = '02';   // se emitió con errores, sin sustituta

    public const MOTIVO_NO_LLEVO_ACABO = '03'; // la operación no se realizó

    public const MOTIVO_NOMINATIVA = '04';     // operación nominativa global

    protected $table = 'facturas';

    /** Los mismos defaults que la migración; ver la nota en `Adeudo`. */
    protected $attributes = [
        'tipo' => self::TIPO_INGRESO,
        'estatus' => self::ESTATUS_BORRADOR,
        'metodo_pago_sat' => 'PUE',
        'moneda' => 'MXN',
        'iva' => 0,
        'intentos' => 0,
    ];

    protected $fillable = [
        'matricula_oferta_id',
        'emisor_id',
        'emisor_rfc',
        'emisor_razon_social',
        'emisor_regimen_fiscal',
        'emisor_cp',
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
        'facturapi_id',
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
        'iedu_motivo',
        'tipo',
        'factura_origen_id',
        'motivo_egreso',
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

    /**
     * La razón social con la que se emitió. Es solo la referencia de dónde
     * salieron los datos: los que valen son las columnas `emisor_*` copiadas
     * aquí. Un real PAC sí necesita este vínculo, porque de él saca el
     * certificado con el que firma.
     */
    public function emisor(): BelongsTo
    {
        return $this->belongsTo(EmisorFiscal::class, 'emisor_id');
    }

    /**
     * El complemento educativo, cuando lo lleva.
     *
     * `hasOne` y no columnas en esta tabla: que NO exista es un hecho con
     * significado —esta factura no ampara enseñanza deducible—, y como fila
     * ausente se lee de un vistazo en vez de como cuatro nulos entre treinta y
     * cinco columnas.
     */
    public function iedu(): HasOne
    {
        return $this->hasOne(FacturaIedu::class, 'factura_id');
    }

    /** La factura que esta nota de crédito reduce. */
    public function origen(): BelongsTo
    {
        return $this->belongsTo(self::class, 'factura_origen_id');
    }

    /** Las notas de crédito emitidas contra esta factura. */
    public function notasCredito(): HasMany
    {
        return $this->hasMany(self::class, 'factura_origen_id');
    }

    public function esNotaCredito(): bool
    {
        return $this->tipo === self::TIPO_EGRESO;
    }

    /**
     * Cuánto de esta factura ya está acreditado.
     *
     * Sólo las notas VIVAS cuentan: una cancelada no reduce nada, y si contara
     * seguiría restando importe de una factura que volvió a valer por completo.
     */
    public function acreditado(): float
    {
        return (float) $this->notasCredito()->vivas()->sum('total');
    }

    /** Lo que la factura vale hoy, ya restadas sus notas de crédito. */
    public function totalEfectivo(): float
    {
        return round((float) $this->total - $this->acreditado(), 2);
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

    /**
     * Las de ingreso: las que dicen cuánto cobró la escuela.
     *
     * Existe porque casi toda pregunta sobre facturación es sobre éstas —
     * cuánto se facturó, cuál fue el último receptor— y sumar las de egreso
     * daría un total inflado por documentos que RESTAN.
     */
    public function scopeDeIngreso(Builder $query): Builder
    {
        return $query->where('tipo', self::TIPO_INGRESO);
    }

    public function scopeTimbradas(Builder $query): Builder
    {
        return $query->where('estatus', self::ESTATUS_TIMBRADA);
    }
}
