<?php

declare(strict_types=1);

namespace App\Models\Finanzas;

use App\Models\Admisiones\MatriculaOferta;
use App\Models\Concerns\TieneAuditoria;
use App\Models\ControlEscolar\Ciclo;
use App\Models\Identidad\Persona;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * becas_alumno (TENANT) — la beca otorgada a un alumno concreto.
 *
 * Cuelga de `matricula_oferta` (persona + oferta) y no de la persona: alguien
 * con dos programas académicos puede tener beca en una y no en la otra.
 *
 * Si la beca es por ciclo, hay una fila por ciclo: renovar es crear la del
 * ciclo siguiente, no editar la anterior. Así el histórico queda intacto y se
 * puede responder "¿tenía beca cuando se le cobró marzo?".
 */
class BecaAlumno extends Model
{
    use TieneAuditoria;

    public const ACTIVA = 'activa';

    /**
     * Otorgada, pero esperando firmas. NO descuenta nada: `aplicaEn()` exige
     * ACTIVA, así que el estado es por sí solo la puerta que la autorización
     * multinivel necesitaba —ninguna guarda aparte—.
     */
    public const POR_AUTORIZAR = 'por_autorizar';

    /** Perdió el descuento temporalmente (p. ej. por un atraso). */
    public const SUSPENDIDA = 'suspendida';

    public const PERDIDA = 'perdida';

    public const POR_RENOVAR = 'por_renovar';

    protected $table = 'becas_alumno';

    protected $fillable = [
        'matricula_oferta_id',
        'beca_id',
        'ciclo_id',
        'estatus',
        'vigente_desde',
        'vigente_hasta',
        'promedio_evaluado',
        'autorizado_por',
        'motivo',
    ];

    protected function casts(): array
    {
        return [
            'vigente_desde' => 'date',
            'vigente_hasta' => 'date',
            'promedio_evaluado' => 'decimal:2',
        ];
    }

    public function matricula(): BelongsTo
    {
        return $this->belongsTo(MatriculaOferta::class, 'matricula_oferta_id');
    }

    public function beca(): BelongsTo
    {
        return $this->belongsTo(Beca::class, 'beca_id');
    }

    public function ciclo(): BelongsTo
    {
        return $this->belongsTo(Ciclo::class);
    }

    public function autorizador(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'autorizado_por');
    }

    public function autorizaciones(): HasMany
    {
        return $this->hasMany(BecaAlumnoAutorizacion::class, 'beca_alumno_id');
    }

    public function evidencias(): HasMany
    {
        return $this->hasMany(BecaAlumnoEvidencia::class, 'beca_alumno_id');
    }

    public function movimientos(): HasMany
    {
        return $this->hasMany(BecaAlumnoMovimiento::class, 'beca_alumno_id')->latest('id');
    }

    /** Las que esperan firma. La cola de quien autoriza. */
    public function scopePorAutorizar(Builder $query): Builder
    {
        return $query->where('estatus', self::POR_AUTORIZAR);
    }

    public function scopeActivas(Builder $query): Builder
    {
        return $query->where('estatus', self::ACTIVA);
    }

    /** ¿Descuenta hoy? Solo si está activa y dentro de su vigencia. */
    public function aplicaEn(?string $fecha = null): bool
    {
        if ($this->estatus !== self::ACTIVA) {
            return false;
        }

        $fecha ??= now()->toDateString();

        if ($this->vigente_desde !== null && $this->vigente_desde->toDateString() > $fecha) {
            return false;
        }

        return $this->vigente_hasta === null || $this->vigente_hasta->toDateString() >= $fecha;
    }
}
