<?php

declare(strict_types=1);

namespace App\Models\Movilidad;

use App\Models\Admisiones\MatriculaOferta;
use App\Models\Concerns\TieneAuditoria;
use App\Models\Identidad\Persona;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * postulaciones_movilidad (TENANT) — alguien pide irse o venir.
 *
 * ── Titular DUAL, exactamente uno ─────────────────────────────────────────
 * `matricula_oferta_id` para el SALIENTE —una matrícula nuestra— y
 * `persona_externa_id` para el ENTRANTE, que es alguien de otra institución.
 * Nunca los dos ni ninguno, con CHECK en MySQL: es el mismo mecanismo que
 * `adeudos` con su titular dual.
 *
 * ── Y sólo al saliente se le revalida ─────────────────────────────────────
 * Un entrante no tiene historial académico nuestro que actualizar. La
 * revalidación de la siguiente rebanada lo comprueba.
 *
 * ── El promedio se CONGELA al postularse ──────────────────────────────────
 * Se calcula del historial y no se captura —un número tecleado es un número que
 * alguien puede acomodar— y se guarda, porque el promedio de hoy no es con el
 * que se le evaluó hace un semestre.
 */
class PostulacionMovilidad extends Model
{
    use TieneAuditoria;

    protected $table = 'postulaciones_movilidad';

    protected $fillable = [
        'convocatoria_id',
        'matricula_oferta_id',
        'persona_externa_id',
        'etapa_id',
        'promedio_acreditado',
        'fecha_postulacion',
        'notas',
    ];

    protected function casts(): array
    {
        return [
            'fecha_postulacion' => 'datetime',
            'promedio_acreditado' => 'decimal:2',
        ];
    }

    public function convocatoria(): BelongsTo
    {
        return $this->belongsTo(ConvocatoriaMovilidad::class, 'convocatoria_id');
    }

    public function matricula(): BelongsTo
    {
        return $this->belongsTo(MatriculaOferta::class, 'matricula_oferta_id');
    }

    public function personaExterna(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'persona_externa_id');
    }

    public function etapa(): BelongsTo
    {
        return $this->belongsTo(EtapaMovilidad::class, 'etapa_id');
    }

    public function estancia(): HasOne
    {
        return $this->hasOne(Estancia::class, 'postulacion_id');
    }

    /** ¿Es alumno nuestro que se va? */
    public function esSaliente(): bool
    {
        return $this->matricula_oferta_id !== null;
    }

    /** Quién es, venga de donde venga. */
    public function quien(): ?string
    {
        return $this->esSaliente()
            ? $this->matricula?->persona?->nombreCompleto()
            : $this->personaExterna?->nombreCompleto();
    }
}
