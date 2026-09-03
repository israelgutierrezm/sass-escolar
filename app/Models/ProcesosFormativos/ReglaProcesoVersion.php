<?php

declare(strict_types=1);

namespace App\Models\ProcesosFormativos;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * reglas_proceso_versiones (TENANT) — QUÉ exige una regla, en un momento dado.
 *
 * ── Se CONGELA en el expediente ────────────────────────────────────────────
 * `expedientes_proceso.regla_version_id` apunta a una de éstas y no se vuelve a
 * mirar: cambiar la configuración mañana no altera un expediente en curso ni
 * uno liberado. Por eso una versión NO se edita una vez que hay expedientes que
 * la citan — se crea la siguiente.
 *
 * ── Un requisito en NULL es «no se pide» ───────────────────────────────────
 * `horas_requeridas` en null no significa cero horas: significa que este
 * proceso no se mide por horas —una experiencia profesional acreditada con
 * constancia—. Cero sería una afirmación distinta, y la que dejaría a cualquiera
 * liberado desde el primer día.
 */
class ReglaProcesoVersion extends Model
{
    use TieneAuditoria;

    /** Cuándo se entrega un papel. */
    public const MOMENTO_SOLICITUD = 'solicitud';

    public const MOMENTO_DURANTE = 'durante';

    public const MOMENTO_LIBERACION = 'liberacion';

    public const MOMENTOS = [
        self::MOMENTO_SOLICITUD => 'Al solicitar',
        self::MOMENTO_DURANTE => 'Durante el proceso',
        self::MOMENTO_LIBERACION => 'Para liberar',
    ];

    protected $table = 'reglas_proceso_versiones';

    protected $attributes = ['version' => 1];

    protected $fillable = [
        'regla_id',
        'version',
        'vigente_desde',
        'obligatorio',
        'horas_requeridas',
        'tolerancia_horas',
        'porcentaje_creditos_minimo',
        'periodo_minimo',
        'solicitud_desde',
        'solicitud_hasta',
        'plazo_maximo_dias',
        'max_horas_dia',
        'max_horas_semana',
        'exige_seguro',
        'exige_convenio_vigente',
        'exige_no_adeudo',
        'exige_aprobacion_coordinador',
        'informes_parciales',
        'periodicidad_informe_dias',
        'exige_informe_final',
        'exige_evaluacion_supervisor',
        'exige_evaluacion_estudiante',
        'exige_carta_aceptacion',
        'exige_carta_termino',
        'emite_constancia',
        'cuenta_para_titulacion',
        'notas',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'vigente_desde' => 'date',
            'solicitud_desde' => 'date',
            'solicitud_hasta' => 'date',
            'obligatorio' => 'boolean',
            'horas_requeridas' => 'integer',
            'tolerancia_horas' => 'integer',
            'porcentaje_creditos_minimo' => 'decimal:2',
            'periodo_minimo' => 'integer',
            'plazo_maximo_dias' => 'integer',
            'max_horas_dia' => 'integer',
            'max_horas_semana' => 'integer',
            'exige_seguro' => 'boolean',
            'exige_convenio_vigente' => 'boolean',
            'exige_no_adeudo' => 'boolean',
            'exige_aprobacion_coordinador' => 'boolean',
            'informes_parciales' => 'integer',
            'periodicidad_informe_dias' => 'integer',
            'exige_informe_final' => 'boolean',
            'exige_evaluacion_supervisor' => 'boolean',
            'exige_evaluacion_estudiante' => 'boolean',
            'exige_carta_aceptacion' => 'boolean',
            'exige_carta_termino' => 'boolean',
            'emite_constancia' => 'boolean',
            'cuenta_para_titulacion' => 'boolean',
        ];
    }

    public function regla(): BelongsTo
    {
        return $this->belongsTo(ReglaProceso::class, 'regla_id');
    }

    public function documentos(): HasMany
    {
        return $this->hasMany(ReglaDocumento::class, 'version_id');
    }

    public function materiasPrevias(): HasMany
    {
        return $this->hasMany(ReglaMateriaPrevia::class, 'version_id');
    }

    public function situacionesPermitidas(): HasMany
    {
        return $this->hasMany(ReglaSituacionPermitida::class, 'version_id');
    }

    /** Las que ya entraron en vigor a esa fecha, de la más nueva a la más vieja. */
    public function scopeVigentesAl(Builder $c, ?string $dia = null): Builder
    {
        return $c->whereDate('vigente_desde', '<=', $dia ?? now()->toDateString())
            ->orderByDesc('vigente_desde')
            ->orderByDesc('version');
    }

    /**
     * ¿Se puede solicitar hoy?
     *
     * Sin ventana declarada está siempre abierta: la mayoría de las escuelas no
     * la usan, y un rango obligatorio las forzaría a inventar fechas.
     */
    public function ventanaAbierta(?string $dia = null): bool
    {
        $hoy = $dia ?? now()->toDateString();

        if ($this->solicitud_desde !== null && $hoy < $this->solicitud_desde->toDateString()) {
            return false;
        }

        return $this->solicitud_hasta === null || $hoy <= $this->solicitud_hasta->toDateString();
    }

    /**
     * Las horas que de verdad hay que juntar para liberar.
     *
     * La tolerancia se resta AQUÍ y no en cada sitio que compare: escrita en
     * dos lados, un día la liberación toleraría y el avance del alumno no, y
     * quien vea «478 de 480» no sabría si ya puede.
     */
    public function horasMinimas(): ?int
    {
        if ($this->horas_requeridas === null) {
            return null;
        }

        return max(0, $this->horas_requeridas - $this->tolerancia_horas);
    }

    /** Los papeles de un momento concreto. */
    public function documentosDe(string $momento): iterable
    {
        return $this->documentos->where('momento', $momento);
    }
}
