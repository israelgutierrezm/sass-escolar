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
 * Una jornada del expediente: qué hizo, cuándo y cuánto duró.
 *
 * ── Los MINUTOS los calcula MySQL ─────────────────────────────────────────
 * `minutos_totales` es una columna generada, así que no se puede escribir ni
 * mentir: sale de las horas y el descanso de esta misma fila. Con el cálculo en
 * PHP bastaría con que un camino lo olvidara —una corrección, una carga
 * masiva— para que un expediente sumara horas que nadie trabajó.
 *
 * ── El ESTADO decide si cuenta ────────────────────────────────────────────
 * `capturada` es una afirmación del alumno; sólo `aprobada` suma. Lo rechazado
 * se CONSERVA con su motivo: borrarlo dejaría al alumno sin saber qué corregir
 * y a la escuela sin poder explicar por qué esa jornada no contó.
 */
class BitacoraHoras extends Model
{
    use SoftDeletes, TieneAuditoria;

    /** La capturó alguien y nadie la ha revisado. */
    public const CAPTURADA = 'capturada';

    /** Cuenta para el total. */
    public const APROBADA = 'aprobada';

    /** No cuenta, y se conserva con su motivo. */
    public const RECHAZADA = 'rechazada';

    protected $table = 'bitacora_horas';

    /**
     * `estado`, `aprobada_por` y `aprobada_en` NO son asignables en masa.
     *
     * Aprobar es un acto con su permiso; por un formulario, cualquiera se
     * aprobaría sus propias horas. Los escribe el servicio con `forceFill`.
     */
    protected $fillable = [
        'expediente_id', 'fecha', 'hora_inicio', 'hora_fin', 'minutos_descanso',
        'actividad', 'modalidad_id', 'evidencia_ruta', 'latitud', 'longitud', 'capturada_por',
    ];

    protected function casts(): array
    {
        return [
            'fecha' => 'date',
            'aprobada_en' => 'datetime',
            'latitud' => 'decimal:5',
            'longitud' => 'decimal:5',
        ];
    }

    public function expediente(): BelongsTo
    {
        return $this->belongsTo(ExpedienteProceso::class, 'expediente_id');
    }

    public function modalidad(): BelongsTo
    {
        return $this->belongsTo(ModalidadProceso::class, 'modalidad_id');
    }

    public function capturadaPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'capturada_por');
    }

    public function aprobadaPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'aprobada_por');
    }

    /** Las que SUMAN. Es la única definición de «horas del alumno». */
    public function scopeAprobadas(Builder $q): Builder
    {
        return $q->where('estado', self::APROBADA);
    }

    /** Las que esperan que alguien las mire. */
    public function scopePorRevisar(Builder $q): Builder
    {
        return $q->where('estado', self::CAPTURADA);
    }

    /**
     * Las que ocupan su franja horaria.
     *
     * Una RECHAZADA no estorba: si no, corregir una jornada mal capturada
     * exigiría borrarla —y con ella su motivo—, que es justo lo que se conserva.
     */
    public function scopeQueOcupanFranja(Builder $q): Builder
    {
        return $q->whereIn('estado', [self::CAPTURADA, self::APROBADA]);
    }

    public function estaAprobada(): bool
    {
        return $this->estado === self::APROBADA;
    }

    /** Las horas con dos decimales, para enseñarlas. */
    public function horas(): float
    {
        return round(((int) $this->minutos_totales) / 60, 2);
    }
}
