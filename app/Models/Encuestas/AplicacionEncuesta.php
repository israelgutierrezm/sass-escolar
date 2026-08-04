<?php

declare(strict_types=1);

namespace App\Models\Encuestas;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * aplicaciones_encuesta (TENANT) — la puesta en marcha de un cuestionario.
 *
 * Cuándo se abre, a quién le llega, si se puede posponer y si es anónima. El
 * cuestionario en sí es de `Encuesta`; aquí vive todo lo que cambia entre una
 * aplicación y la siguiente.
 */
class AplicacionEncuesta extends Model
{
    use TieneAuditoria;

    public const BORRADOR = 'borrador';

    public const PUBLICADA = 'publicada';

    public const CERRADA = 'cerrada';

    /** Se contesta una vez por cada docente evaluado. */
    public const DOCENTE = 'docente';

    /** Se contesta una sola vez: pregunta por un tema, no por una persona. */
    public const GENERAL = 'general';

    protected $table = 'aplicaciones_encuesta';

    protected $fillable = [
        'encuesta_id', 'titulo', 'instrucciones', 'tipo',
        'abre_en', 'cierra_en', 'obligatoria', 'anonima', 'estado',
    ];

    protected function casts(): array
    {
        return [
            'abre_en' => 'datetime',
            'cierra_en' => 'datetime',
            'obligatoria' => 'boolean',
            'anonima' => 'boolean',
        ];
    }

    public function encuesta(): BelongsTo
    {
        return $this->belongsTo(Encuesta::class, 'encuesta_id');
    }

    public function destinos(): HasMany
    {
        return $this->hasMany(AplicacionDestino::class, 'aplicacion_id');
    }

    /** A quién se evalúa. Vacío en una encuesta general. */
    public function sujetos(): HasMany
    {
        return $this->hasMany(Sujeto::class, 'aplicacion_id');
    }

    public function participaciones(): HasMany
    {
        return $this->hasMany(Participacion::class, 'aplicacion_id');
    }

    public function respuestas(): HasMany
    {
        return $this->hasMany(Respuesta::class, 'aplicacion_id');
    }

    /**
     * Las que hoy se pueden contestar.
     *
     * Publicada, ya abierta y no cerrada. Las fechas nulas no acotan: sin
     * `abre_en` vale desde que se publica, y sin `cierra_en` hasta que alguien
     * la cierre a mano.
     */
    public function scopeAbiertas(Builder $q): Builder
    {
        return $q->where('estado', self::PUBLICADA)
            ->where(fn (Builder $s) => $s->whereNull('abre_en')->orWhere('abre_en', '<=', now()))
            ->where(fn (Builder $s) => $s->whereNull('cierra_en')->orWhere('cierra_en', '>=', now()));
    }

    public function estaAbierta(): bool
    {
        return $this->estado === self::PUBLICADA
            && ($this->abre_en === null || $this->abre_en->lte(now()))
            && ($this->cierra_en === null || $this->cierra_en->gte(now()));
    }

    public function esDocente(): bool
    {
        return $this->tipo === self::DOCENTE;
    }
}
