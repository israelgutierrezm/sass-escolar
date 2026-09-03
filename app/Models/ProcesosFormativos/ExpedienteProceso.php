<?php

declare(strict_types=1);

namespace App\Models\ProcesosFormativos;

use App\Models\Admisiones\MatriculaOferta;
use App\Models\Concerns\TieneAuditoria;
use App\Models\Identidad\Persona;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * El trámite de UNA matrícula en UN tipo de proceso formativo.
 *
 * ── Qué se guarda y qué NO ────────────────────────────────────────────────
 * El expediente guarda lo que le pasó a ESTE alumno: su estado, su
 * organización, sus fechas y las horas que se le exigieron. Lo que la regla
 * dice se lee de `reglaVersion`, congelada al abrir — y sólo lo que puede
 * llevar excepción se copia (`horas_requeridas`).
 *
 * ── El estado NO se escribe con `update()` ────────────────────────────────
 * Todas las transiciones pasan por {@see TransicionDeExpediente}: valida el
 * origen, el permiso y el alcance, escribe la bitácora y bloquea la fila. Un
 * `update(['estado' => …])` suelto se salta las cinco cosas a la vez.
 */
class ExpedienteProceso extends Model
{
    use HasFactory, SoftDeletes, TieneAuditoria;

    protected $table = 'expedientes_proceso';

    /**
     * `estado` NO es asignable en masa, a propósito.
     *
     * Es la trampa que este proyecto ya se comió al revés con `cupo_ocupado` y
     * con `sesion_caja_id`: lo que no está aquí se descarta EN SILENCIO. Aquí
     * el silencio es la defensa —un formulario no puede mover el trámite— y el
     * servicio lo escribe con `forceFill`.
     */
    protected $fillable = [
        'matricula_oferta_id', 'tipo_proceso_id', 'regla_version_id',
        'organizacion_id', 'plaza_id', 'modalidad_id', 'contacto_supervisor_id', 'responsable_interno_id',
        'fecha_solicitud', 'fecha_aprobacion', 'fecha_inicio', 'fecha_fin_programada',
        'fecha_conclusion', 'horas_requeridas', 'organizacion_propuesta', 'notas',
    ];

    protected function casts(): array
    {
        return [
            'estado' => EstadoExpediente::class,
            'fecha_solicitud' => 'date',
            'fecha_aprobacion' => 'date',
            'fecha_inicio' => 'date',
            'fecha_fin_programada' => 'date',
            'fecha_conclusion' => 'date',
            'organizacion_propuesta' => 'array',
        ];
    }

    public function matricula(): BelongsTo
    {
        return $this->belongsTo(MatriculaOferta::class, 'matricula_oferta_id');
    }

    public function tipoProceso(): BelongsTo
    {
        return $this->belongsTo(TipoProcesoFormativo::class, 'tipo_proceso_id');
    }

    public function reglaVersion(): BelongsTo
    {
        return $this->belongsTo(ReglaProcesoVersion::class, 'regla_version_id');
    }

    public function organizacion(): BelongsTo
    {
        return $this->belongsTo(OrganizacionReceptora::class, 'organizacion_id');
    }

    public function plaza(): BelongsTo
    {
        return $this->belongsTo(PlazaProceso::class, 'plaza_id');
    }

    /**
     * Cómo lo hace: presencial, mixta o remota.
     *
     * Propia y no derivada de la plaza: un expediente puede no tener plaza —los
     * tipos con `exige_plaza` apagado—, y derivándola la mitad se quedaría sin
     * modalidad.
     */
    public function modalidad(): BelongsTo
    {
        return $this->belongsTo(ModalidadProceso::class, 'modalidad_id');
    }

    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(OrganizacionContacto::class, 'contacto_supervisor_id');
    }

    public function responsableInterno(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'responsable_interno_id');
    }

    public function transiciones(): HasMany
    {
        return $this->hasMany(TransicionExpediente::class, 'expediente_id')->orderByDesc('momento');
    }

    public function documentos(): HasMany
    {
        return $this->hasMany(DocumentoExpedienteFormativo::class, 'expediente_id');
    }

    public function excepciones(): HasMany
    {
        return $this->hasMany(ExcepcionExpediente::class, 'expediente_id');
    }

    public function horas(): HasMany
    {
        return $this->hasMany(BitacoraHoras::class, 'expediente_id');
    }

    public function informes(): HasMany
    {
        return $this->hasMany(InformeProceso::class, 'expediente_id')
            ->orderBy('fecha_limite')
            ->orderBy('numero');
    }

    public function evaluaciones(): HasMany
    {
        return $this->hasMany(EvaluacionProceso::class, 'expediente_id');
    }

    /**
     * ¿Se le pueden registrar horas?
     *
     * Sólo mientras el proceso corre. Antes de iniciar no hay nada que
     * registrar, y después de concluir las horas nuevas moverían un total que
     * ya se dio por bueno — con el expediente liberado, además, cambiarían lo
     * que dice una constancia emitida.
     *
     * SUSPENDIDO sí admite: una suspensión se levanta, y mientras tanto puede
     * hacer falta capturar lo que quedó pendiente de los días anteriores.
     */
    public function admiteHoras(): bool
    {
        return in_array($this->estado, [
            EstadoExpediente::EnCurso,
            EstadoExpediente::Suspendido,
        ], true);
    }

    /** Los que todavía cuentan: ni rechazados ni cancelados. */
    public function scopeVivos(Builder $q): Builder
    {
        return $q->whereIn('estado', array_map(
            fn (EstadoExpediente $e) => $e->value,
            EstadoExpediente::ocupanLaMatricula(),
        ));
    }

    /** Los que esperan que alguien de la escuela haga algo. */
    public function scopeEnBandeja(Builder $q): Builder
    {
        return $q->whereIn('estado', [
            EstadoExpediente::Solicitado->value,
            EstadoExpediente::EnRevision->value,
            EstadoExpediente::Aprobado->value,
        ]);
    }

    /**
     * ¿Se le perdonó este requisito, y quién?
     *
     * Devuelve la excepción y no un booleano: el impedimento que desaparece
     * tiene que poder NOMBRAR a quien lo autorizó. Un `true` pelado deja el
     * expediente diciendo que cumple sin decir por qué.
     */
    public function excepcionDe(string $requisito): ?ExcepcionExpediente
    {
        return $this->excepciones->firstWhere('requisito', $requisito);
    }

    /** Ya está asignado a una organización y con fechas. */
    public function estaAsignado(): bool
    {
        return $this->organizacion_id !== null && $this->fecha_inicio !== null;
    }
}
