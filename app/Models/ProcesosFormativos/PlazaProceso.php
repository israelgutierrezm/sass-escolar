<?php

declare(strict_types=1);

namespace App\Models\ProcesosFormativos;

use App\Models\Academico\ProgramaAcademico;
use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * plazas_proceso (TENANT) — lo que una organización ofrece.
 *
 * ── El CUPO lo protege la BASE ─────────────────────────────────────────────
 * `cupo_ocupado <= cupo` es un CHECK, y la asignación toma `lockForUpdate()`
 * sobre la plaza dentro de su transacción. Dos alumnos aceptando la última
 * plaza a la vez pasan los dos un conteo hecho antes de escribir; el bloqueo es
 * lo que decide, y el CHECK es la red por si alguien escribe por otro camino.
 * Es la lección del apartado de licencia de las clases en línea.
 *
 * ── Sin programas señalados, se ofrece a TODOS ─────────────────────────────
 * Misma regla que el alcance de la organización y que las vacantes de la bolsa:
 * exigir al menos uno obligaría a palomear veinte cada vez, y la mitad de las
 * plazas reales aceptan a cualquiera.
 */
class PlazaProceso extends Model
{
    use TieneAuditoria;

    protected $table = 'plazas_proceso';

    protected $fillable = [
        'organizacion_id',
        'tipo_proceso_id',
        'modalidad_id',
        'nombre',
        'descripcion',
        'actividades',
        'ubicacion',
        'horario',
        'cupo',
        'fecha_inicio',
        'fecha_cierre',
        'duracion_estimada_horas',
        'apoyo_economico',
        'requisitos',
        'responsable',
        'abierta',
    ];

    /**
     * `cupo_ocupado` NO es asignable en masa, a propósito.
     *
     * Lo mueve la asignación dentro de su transacción y con la plaza bloqueada;
     * dejarlo en el `fillable` permitiría que un formulario lo escribiera y el
     * cupo dejaría de significar nada. Es la trampa que ya se cobró
     * `Pago::sesion_caja_id`, sólo que al revés.
     */
    protected function casts(): array
    {
        return [
            'fecha_inicio' => 'date',
            'fecha_cierre' => 'date',
            'cupo' => 'integer',
            'cupo_ocupado' => 'integer',
            'duracion_estimada_horas' => 'integer',
            'apoyo_economico' => 'decimal:2',
            'abierta' => 'boolean',
        ];
    }

    public function organizacion(): BelongsTo
    {
        return $this->belongsTo(OrganizacionReceptora::class, 'organizacion_id');
    }

    public function tipoProceso(): BelongsTo
    {
        return $this->belongsTo(TipoProcesoFormativo::class, 'tipo_proceso_id');
    }

    public function modalidad(): BelongsTo
    {
        return $this->belongsTo(ModalidadProceso::class, 'modalidad_id');
    }

    public function programasAcademicos(): BelongsToMany
    {
        return $this->belongsToMany(
            ProgramaAcademico::class,
            'plaza_programas',
            'plaza_id',
            'programa_academico_id',
        )->wherePivotNull('deleted_at')->withTimestamps();
    }

    public function lugaresLibres(): int
    {
        return max(0, (int) $this->cupo - (int) $this->cupo_ocupado);
    }

    /** ¿Se le pasó la fecha de cierre? Otra pregunta que «está cerrada». */
    public function estaVencida(?string $hoy = null): bool
    {
        return $this->fecha_cierre !== null
            && $this->fecha_cierre->toDateString() < ($hoy ?? now()->toDateString());
    }

    /**
     * ¿Se puede asignar a alguien HOY?
     *
     * Las tres condiciones cruzadas: abierta, con lugar y dentro de fecha. Una
     * plaza «abierta» con la fecha pasada seguiría recibiendo gente, y una llena
     * también — las dos se ven bien en la pantalla y ninguna da error.
     */
    public function admiteA(?string $hoy = null): bool
    {
        return $this->abierta && $this->lugaresLibres() > 0 && ! $this->estaVencida($hoy);
    }

    /**
     * ¿Se ofrece a este programa? Sin filas, a todos.
     */
    public function aceptaAlPrograma(?int $programaId): bool
    {
        $programas = $this->relationLoaded('programasAcademicos')
            ? $this->programasAcademicos
            : $this->programasAcademicos()->get();

        return $programas->isEmpty() || $programas->contains('id', $programaId);
    }

    public function scopeDisponibles(Builder $c, ?string $hoy = null): Builder
    {
        $dia = $hoy ?? now()->toDateString();

        return $c->where('abierta', true)
            ->whereColumn('cupo_ocupado', '<', 'cupo')
            ->where(fn (Builder $q) => $q->whereNull('fecha_cierre')->orWhereDate('fecha_cierre', '>=', $dia));
    }
}
