<?php

declare(strict_types=1);

namespace App\Models\Nomina;

use App\Models\Concerns\TieneAuditoria;
use App\Models\Identidad\Persona;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * expedientes_laborales (TENANT) — el vínculo laboral de una persona.
 *
 * ── Complementa a `docentes`, no lo reemplaza ─────────────────────────────
 * `docentes` es identidad ACADÉMICA y de ahí sale a qué materias se le puede
 * asignar; esto es el vínculo laboral, y lo tiene también quien nunca da clase.
 * Un docente de asignatura tiene los dos.
 *
 * ── Una persona puede tener varios ────────────────────────────────────────
 * Recontratación y doble plaza. Lo que no se repite es el número de empleado.
 */
class ExpedienteLaboral extends Model
{
    use TieneAuditoria;

    protected $table = 'expedientes_laborales';

    protected $fillable = [
        'persona_id',
        'numero_empleado',
        'tipo_contrato_id',
        'situacion_id',
        'fecha_ingreso',
        'fecha_baja',
        'motivo_baja_id',
        'banco',
        'clabe',
        'notas',
    ];

    protected function casts(): array
    {
        return [
            'fecha_ingreso' => 'date',
            'fecha_baja' => 'date',
        ];
    }

    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'persona_id');
    }

    public function tipoContrato(): BelongsTo
    {
        return $this->belongsTo(TipoContrato::class, 'tipo_contrato_id');
    }

    public function situacion(): BelongsTo
    {
        return $this->belongsTo(SituacionEmpleado::class, 'situacion_id');
    }

    public function motivoBaja(): BelongsTo
    {
        return $this->belongsTo(MotivoBajaLaboral::class, 'motivo_baja_id');
    }

    public function adscripciones(): HasMany
    {
        return $this->hasMany(Adscripcion::class, 'expediente_laboral_id');
    }

    public function esquemas(): HasMany
    {
        return $this->hasMany(EsquemaPercepcion::class, 'expediente_laboral_id');
    }

    /**
     * Cuánto gana en una fecha.
     *
     * Se pregunta POR FECHA y no «el abierto»: la nómina calcula periodos
     * pasados, y un recibo de la quincena anterior tiene que usar el sueldo que
     * regía entonces, no el de hoy.
     */
    public function esquemaEn(string $fecha): ?EsquemaPercepcion
    {
        return $this->esquemas()->vigentesEn($fecha)->orderByDesc('vigente_desde')->first();
    }

    /** ¿Sigue trabajando aquí? Lo dice la fecha de baja y sólo ella. */
    public function sigueContratado(): bool
    {
        return $this->fecha_baja === null;
    }

    /** Los que siguen contratados. */
    public function scopeVigentes(Builder $consulta): Builder
    {
        return $consulta->whereNull('fecha_baja');
    }

    /**
     * A quién se le paga: sigue contratado Y su situación entra a nómina.
     *
     * Las dos condiciones hacen falta. Sin la primera se le pagaría a quien
     * renunció; sin la segunda, a quien está de licencia sin goce. Y la
     * segunda va contra la BANDERA del catálogo, no contra una clave.
     */
    public function scopeEnNomina(Builder $consulta): Builder
    {
        return $consulta
            ->vigentes()
            ->whereHas('situacion', fn (Builder $q) => $q->where('entra_a_nomina', true));
    }

    /**
     * La adscripción que manda hoy.
     *
     * La principal si la hay; si no, la más reciente que siga vigente. Sin este
     * respaldo, un expediente al que nadie marcó la principal saldría en los
     * reportes sin puesto ni campus, que es peor que enseñar el único que tiene.
     */
    public function adscripcionActual(): ?Adscripcion
    {
        $vigentes = $this->adscripciones->filter(fn (Adscripcion $a) => $a->estaVigente());

        return $vigentes->firstWhere('es_principal', true)
            ?? $vigentes->sortByDesc('vigente_desde')->first();
    }
}
