<?php

declare(strict_types=1);

namespace App\Models\Lms;

use App\Models\Concerns\TieneAuditoria;
use App\Models\Identidad\Persona;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * rubricas (TENANT) — con qué se califica un trabajo sin respuesta correcta.
 *
 * Dos ámbitos en una tabla: las de la ESCUELA (`plataforma`, sin dueño) y las de
 * cada docente (`docente`, con `persona_id`). Ver la migración para el porqué.
 *
 * El total NO es una columna: es la suma de los máximos de cada criterio, y
 * cada máximo es el nivel más alto. Un solo sitio donde viven los puntos.
 */
class Rubrica extends Model
{
    use SoftDeletes;
    use TieneAuditoria;

    /** De la escuela: la ve y la usa cualquiera. */
    public const PLATAFORMA = 'plataforma';

    /** De una persona: sólo ella. */
    public const DOCENTE = 'docente';

    protected $table = 'rubricas';

    protected $fillable = [
        'nombre',
        'descripcion',
        'ambito',
        'persona_id',
        'activa',
    ];

    protected function casts(): array
    {
        return ['activa' => 'boolean'];
    }

    public function criterios(): HasMany
    {
        return $this->hasMany(RubricaCriterio::class, 'rubrica_id')->orderBy('orden')->orderBy('id');
    }

    public function dueno(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'persona_id');
    }

    /** Las actividades que se califican con ella. */
    public function actividades(): HasMany
    {
        return $this->hasMany(Actividad::class, 'rubrica_id');
    }

    public function esDePlataforma(): bool
    {
        return $this->ambito === self::PLATAFORMA;
    }

    /**
     * Sobre cuántos puntos califica: la suma de los máximos de cada criterio.
     *
     * Cero significa que todavía no sirve para calificar —sin criterios, o con
     * criterios sin niveles—, y quien intente amarrarla a una actividad se topa
     * con eso antes de que un alumno reciba un cero inventado.
     */
    public function total(): float
    {
        return round(
            $this->criterios->sum(fn (RubricaCriterio $c) => $c->maximo()),
            2,
        );
    }

    /** Se puede usar para calificar de verdad. */
    public function calificable(): bool
    {
        return $this->total() > 0;
    }

    /**
     * Ya calificó a alguien, así que su estructura queda congelada.
     *
     * Se pregunta por las EVALUACIONES y no por las actividades amarradas:
     * amarrarla y todavía no usarla no compromete ningún número, y congelarla
     * ahí dejaría al docente sin poder corregir una errata el mismo día que la
     * puso.
     */
    public function estaEnUso(): bool
    {
        return EntregaRubrica::query()
            ->whereIn('criterio_id', $this->criterios()->select('id'))
            ->exists();
    }

    public function scopeActivas(Builder $query): Builder
    {
        return $query->where('activa', true);
    }

    /**
     * Las que esta persona puede VER: las de la escuela y las suyas.
     *
     * Las de otro docente no aparecen ni con permiso de gestionar: una rúbrica
     * propia es un borrador de trabajo, y quien la quiera compartir la publica
     * como de plataforma.
     */
    public function scopeVisiblesPara(Builder $query, ?int $personaId): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->where('ambito', self::PLATAFORMA)
            ->orWhere(fn (Builder $mias) => $mias
                ->where('ambito', self::DOCENTE)
                ->where('persona_id', $personaId)));
    }
}
