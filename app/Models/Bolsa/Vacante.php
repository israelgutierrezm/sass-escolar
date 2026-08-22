<?php

declare(strict_types=1);

namespace App\Models\Bolsa;

use App\Models\Academico\Campus;
use App\Models\Academico\Carrera;
use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * vacantes (TENANT) — un puesto que una empresa ofrece a la escuela.
 *
 * ── «Sin carreras señaladas» significa PARA TODAS ─────────────────────────
 * No es un descuido de captura: la mitad de las vacantes reales buscan «recién
 * egresados de lo que sea», y exigir al menos una carrera obligaría a palomear
 * las veinte de la escuela cada vez. Lo que sí hay que hacer es decirlo en la
 * pantalla, para que nadie tenga que deducirlo.
 */
class Vacante extends Model
{
    use TieneAuditoria;

    protected $table = 'vacantes';

    protected $fillable = [
        'empresa_id',
        'titulo',
        'descripcion',
        'modalidad_id',
        'tipo_jornada_id',
        'salario_min',
        'salario_max',
        'campus_id',
        'vacantes_disponibles',
        'ubicacion',
        'fecha_publicacion',
        'fecha_cierre',
        'situacion_id',
    ];

    protected function casts(): array
    {
        return [
            'fecha_publicacion' => 'date',
            'fecha_cierre' => 'date',
            'salario_min' => 'decimal:2',
            'salario_max' => 'decimal:2',
        ];
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class, 'empresa_id');
    }

    public function modalidad(): BelongsTo
    {
        return $this->belongsTo(ModalidadTrabajo::class, 'modalidad_id');
    }

    public function jornada(): BelongsTo
    {
        return $this->belongsTo(TipoJornada::class, 'tipo_jornada_id');
    }

    public function situacion(): BelongsTo
    {
        return $this->belongsTo(SituacionVacante::class, 'situacion_id');
    }

    public function campus(): BelongsTo
    {
        return $this->belongsTo(Campus::class, 'campus_id');
    }

    public function carreras(): BelongsToMany
    {
        return $this->belongsToMany(Carrera::class, 'vacante_carreras', 'vacante_id', 'carrera_id')
            ->wherePivotNull('deleted_at')
            ->withTimestamps();
    }

    public function habilidades(): BelongsToMany
    {
        return $this->belongsToMany(Habilidad::class, 'vacante_habilidades', 'vacante_id', 'habilidad_id')
            ->withPivot('indispensable')
            ->wherePivotNull('deleted_at')
            ->withTimestamps();
    }

    /** ¿Se le pasó la fecha de cierre? */
    public function estaVencida(): bool
    {
        return $this->fecha_cierre !== null && $this->fecha_cierre->lt(now()->startOfDay());
    }

    /**
     * Las que hoy admiten postulaciones.
     *
     * Tres condiciones, y las tres hacen falta: abierta, con la fecha de cierre
     * por delante —o sin ella— y de una empresa que no esté vetada. Sin la
     * tercera, vetar a un empleador dejaría vivas las vacantes que ya publicó,
     * que es justo lo que se quiso impedir.
     */
    public function scopeVigentes(Builder $consulta): Builder
    {
        return $consulta
            ->whereHas('situacion', fn (Builder $q) => $q->where('clave', 'abierta'))
            ->where(fn (Builder $q) => $q->whereNull('fecha_cierre')->orWhereDate('fecha_cierre', '>=', now()->toDateString()))
            ->whereHas('empresa', fn (Builder $q) => $q->publicables());
    }

    /**
     * Las que le aplican a una carrera concreta.
     *
     * Incluye las que NO señalan ninguna: ésas son para todas, y dejarlas fuera
     * escondería del tablero del alumno la mitad de la oferta real.
     */
    public function scopeParaCarrera(Builder $consulta, ?int $carreraId): Builder
    {
        return $consulta->where(fn (Builder $q) => $q
            ->whereDoesntHave('carreras')
            ->when($carreraId !== null, fn (Builder $c) => $c->orWhereHas(
                'carreras',
                fn (Builder $cc) => $cc->where('carreras.id', $carreraId),
            )));
    }
}
