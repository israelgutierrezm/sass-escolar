<?php

declare(strict_types=1);

namespace App\Models\Movilidad;

use App\Models\Admisiones\DocumentoRequerido;
use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * convocatorias_movilidad (TENANT) — una llamada abierta bajo un convenio.
 *
 * ── `direccion` es columna y no catálogo ──────────────────────────────────
 * SALIENTE y ENTRANTE son dos caminos del código, no dos filas de una lista: al
 * saliente se le revalidan materias en SU historial académico y al entrante
 * nunca se le escribe uno. Una fila nueva no enseñaría un tercer camino.
 *
 * ── Los requisitos se REUSAN de admisiones ────────────────────────────────
 * `documentos_requeridos` ya es la lista de papeles de la escuela. Una segunda
 * sería un segundo lugar donde configurar «identificación oficial».
 */
class ConvocatoriaMovilidad extends Model
{
    use TieneAuditoria;

    public const SALIENTE = 'saliente';

    public const ENTRANTE = 'entrante';

    protected $table = 'convocatorias_movilidad';

    protected $fillable = [
        'convenio_id',
        'titulo',
        'direccion',
        'periodo',
        'cupo',
        'promedio_minimo',
        'fecha_apertura',
        'fecha_cierre',
        'descripcion',
    ];

    protected function casts(): array
    {
        return [
            'fecha_apertura' => 'date',
            'fecha_cierre' => 'date',
            'promedio_minimo' => 'decimal:2',
        ];
    }

    public function convenio(): BelongsTo
    {
        return $this->belongsTo(Convenio::class, 'convenio_id');
    }

    public function postulaciones(): HasMany
    {
        return $this->hasMany(PostulacionMovilidad::class, 'convocatoria_id');
    }

    public function requisitos(): BelongsToMany
    {
        return $this->belongsToMany(
            DocumentoRequerido::class,
            'convocatoria_requisitos',
            'convocatoria_id',
            'documento_requerido_id',
        )->wherePivotNull('deleted_at')->withTimestamps();
    }

    public function esSaliente(): bool
    {
        return $this->direccion === self::SALIENTE;
    }

    /** ¿Se le pasó la fecha de cierre? */
    public function estaCerrada(): bool
    {
        return $this->fecha_cierre->lt(now()->startOfDay());
    }

    /**
     * Cuántos lugares quedan.
     *
     * Se cuentan los ACEPTADOS por la bandera del catálogo, no por la clave:
     * quien ya está en curso o concluyó sigue ocupando su lugar. Contar sólo la
     * etapa «aceptado» liberaría el cupo en cuanto alguien avanzara, y la
     * escuela mandaría a dos personas a la misma plaza.
     */
    public function lugaresLibres(): int
    {
        $ocupados = $this->postulaciones()
            ->whereHas('etapa', fn (Builder $q) => $q->where('acepta', true))
            ->count();

        return max(0, (int) $this->cupo - $ocupados);
    }

    /** Las que hoy reciben postulaciones: abiertas, en fecha y con convenio vivo. */
    public function scopeAbiertas(Builder $consulta): Builder
    {
        return $consulta
            ->whereDate('fecha_apertura', '<=', now()->toDateString())
            ->whereDate('fecha_cierre', '>=', now()->toDateString())
            ->whereHas('convenio', fn (Builder $q) => $q->vigentes());
    }
}
