<?php

declare(strict_types=1);

namespace App\Models\Permanencia;

use App\Models\Academico\Campus;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\Concerns\TieneAuditoria;
use App\Models\ControlEscolar\Ciclo;
use App\Models\Identidad\Usuario;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * casos_permanencia (TENANT) — el seguimiento humano de una situación.
 *
 * ── Cuelga de la MATRÍCULA ─────────────────────────────────────────────────
 * Como el historial, la conducta y la cartera. Quien estudia dos programas puede
 * necesitar acompañamiento en uno y no en el otro.
 *
 * ── UNO abierto por matrícula, y lo sostiene la base ───────────────────────
 * Con dos, las intervenciones se repartirían entre ellos y nadie sabría dónde
 * anotar la siguiente llamada. Lo vigila un único sobre columna generada.
 *
 * ── El campus se COPIA al abrir ────────────────────────────────────────────
 * No se lee por relación: hace barato el recorte de la bandeja y, sobre todo, lo
 * hace estable. Un alumno que cambia de plantel no puede hacer que un caso
 * cerrado desaparezca del reporte del plantel donde de verdad se atendió.
 */
class CasoPermanencia extends Model
{
    use TieneAuditoria;

    protected $table = 'casos_permanencia';

    /** Las tres prioridades. Es una decisión humana, no derivada del riesgo. */
    public const PRIORIDADES = ['baja', 'media', 'alta'];

    protected $attributes = ['prioridad' => 'media'];

    protected $fillable = [
        'folio',
        'matricula_oferta_id',
        'campus_id',
        'ciclo_id',
        'estado',
        'prioridad',
        'nivel_riesgo_apertura_id',
        'puntaje_apertura',
        'responsable_id',
        'abierto_por',
        'abierto_en',
        'sla_vence_en',
        'primer_contacto_en',
        'plan_intervencion',
        'cerrado_en',
        'motivo_cierre_id',
        'resultado',
        'caso_origen_id',
    ];

    protected function casts(): array
    {
        return [
            'estado' => EstadoCaso::class,
            'abierto_en' => 'datetime',
            'sla_vence_en' => 'datetime',
            'primer_contacto_en' => 'datetime',
            'cerrado_en' => 'datetime',
            'puntaje_apertura' => 'integer',
        ];
    }

    public function matricula(): BelongsTo
    {
        return $this->belongsTo(MatriculaOferta::class, 'matricula_oferta_id');
    }

    public function campus(): BelongsTo
    {
        return $this->belongsTo(Campus::class, 'campus_id');
    }

    public function ciclo(): BelongsTo
    {
        return $this->belongsTo(Ciclo::class, 'ciclo_id');
    }

    public function responsable(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'responsable_id');
    }

    public function abiertoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'abierto_por');
    }

    public function nivelApertura(): BelongsTo
    {
        return $this->belongsTo(NivelRiesgo::class, 'nivel_riesgo_apertura_id');
    }

    public function motivoCierre(): BelongsTo
    {
        return $this->belongsTo(MotivoCierreCaso::class, 'motivo_cierre_id');
    }

    public function origen(): BelongsTo
    {
        return $this->belongsTo(self::class, 'caso_origen_id');
    }

    public function alertas(): BelongsToMany
    {
        return $this->belongsToMany(Alerta::class, 'caso_alerta', 'caso_id', 'alerta_id')
            ->withPivot('sumada_en');
    }

    public function equipo(): HasMany
    {
        return $this->hasMany(CasoEquipo::class, 'caso_id');
    }

    public function intervenciones(): HasMany
    {
        return $this->hasMany(Intervencion::class, 'caso_id')->orderByDesc('fecha');
    }

    public function tareas(): HasMany
    {
        return $this->hasMany(TareaCaso::class, 'caso_id');
    }

    public function transiciones(): HasMany
    {
        return $this->hasMany(TransicionCaso::class, 'caso_id')->orderBy('momento');
    }

    public function accesos(): HasMany
    {
        return $this->hasMany(AccesoCaso::class, 'caso_id');
    }

    public function scopeAbiertos(Builder $c): Builder
    {
        return $c->where('estado', '!=', EstadoCaso::Cerrado->value);
    }

    public function scopeSinAsignar(Builder $c): Builder
    {
        return $c->abiertos()->whereNull('responsable_id');
    }

    /**
     * Los que se pasaron del compromiso de primer contacto.
     *
     * Sólo cuenta si NO ha habido contacto: uno atendido a tiempo no está
     * vencido aunque siga abierto, y contarlo llenaría la cola de casos que ya
     * se atendieron. Y los CERRADOS no cuentan, obviamente.
     */
    public function scopeSlaVencido(Builder $c, ?string $momento = null): Builder
    {
        return $c->abiertos()
            ->whereNotNull('sla_vence_en')
            ->whereNull('primer_contacto_en')
            ->where('sla_vence_en', '<', $momento ?? now());
    }

    /** ¿Se pasó de su compromiso de primer contacto? */
    public function slaVencido(): bool
    {
        return $this->sla_vence_en !== null
            && $this->primer_contacto_en === null
            && ! $this->estado->esTerminal()
            && $this->sla_vence_en->isPast();
    }

    /**
     * Cuánto se tardó en el primer contacto, en horas.
     *
     * Null mientras no lo haya: es el indicador que mide si esto sirve, y un
     * cero mientras nadie ha llamado sería una mentira que además se promedia
     * bien.
     */
    public function horasHastaElPrimerContacto(): ?int
    {
        if ($this->primer_contacto_en === null) {
            return null;
        }

        return (int) $this->abierto_en->diffInHours($this->primer_contacto_en);
    }
}
