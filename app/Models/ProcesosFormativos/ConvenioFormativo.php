<?php

declare(strict_types=1);

namespace App\Models\ProcesosFormativos;

use App\Models\Concerns\TieneAuditoria;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * convenios_formativos (TENANT) — el acuerdo firmado con la receptora.
 *
 * ── VENCIDO no es lo mismo que SUSPENDIDO ──────────────────────────────────
 * `estaVencido()` sale de la FECHA; la situación dice si la escuela lo tiene
 * suspendido o en firma. `estaVigente()` cruza las dos, igual que
 * `Convenio` de Movilidad y `Vacante` de la bolsa: sin la fecha, un convenio
 * caducado seguiría amparando asignaciones nuevas; sin la situación, uno
 * suspendido a mitad de su vigencia las seguiría amparando también.
 *
 * ── Renovar CREA otra fila; la vieja NO se edita ───────────────────────────
 * Un convenio es un papel fechado: cambiarle las fechas al renovarlo borraría
 * bajo qué acuerdo estuvo cada alumno que ya pasó por ahí. La nueva apunta a la
 * anterior y las dos se conservan — mismo criterio que el acta de corrección y
 * la nota de crédito.
 *
 * `version` se escribe UNA vez al crear y no se recalcula: la cadena es
 * inmutable, así que no puede divergir, y sin ella pintar «v3» en un listado de
 * doscientos convenios exigiría recorrer la cadena por cada renglón.
 */
class ConvenioFormativo extends Model
{
    use TieneAuditoria;

    protected $table = 'convenios_formativos';

    /*
     * La version por omision, TAMBIEN en memoria.
     *
     * La base la pone al insertar, pero el objeto recien creado se queda con
     * null hasta que alguien lo relee — y `renovar()` calcula la siguiente
     * sumandole uno. Con null, la renovacion nacia con version 1 en vez de 2,
     * sin un solo error. Mismo remedio que `ReporteEscuela::$attributes`.
     */
    protected $attributes = ['version' => 1];

    protected $fillable = [
        'organizacion_id',
        'tipo_convenio_id',
        'folio',
        'version',
        'convenio_anterior_id',
        'vigente_desde',
        'vigente_hasta',
        'situacion_id',
        'documento_ruta',
        'notas',
    ];

    protected function casts(): array
    {
        return [
            'vigente_desde' => 'date',
            'vigente_hasta' => 'date',
            'version' => 'integer',
        ];
    }

    public function organizacion(): BelongsTo
    {
        return $this->belongsTo(OrganizacionReceptora::class, 'organizacion_id');
    }

    public function tipo(): BelongsTo
    {
        return $this->belongsTo(TipoConvenioFormativo::class, 'tipo_convenio_id');
    }

    public function situacion(): BelongsTo
    {
        return $this->belongsTo(SituacionConvenioFormativo::class, 'situacion_id');
    }

    public function anterior(): BelongsTo
    {
        return $this->belongsTo(self::class, 'convenio_anterior_id');
    }

    /** La renovación que lo sustituyó, si la hay. */
    public function renovacion(): HasOne
    {
        return $this->hasOne(self::class, 'convenio_anterior_id');
    }

    /** ¿Se le pasó la fecha? Es otra pregunta que «está suspendido». */
    public function estaVencido(?string $hoy = null): bool
    {
        return $this->vigente_hasta !== null
            && $this->vigente_hasta->toDateString() < ($hoy ?? now()->toDateString());
    }

    /**
     * ¿Todavía no empieza?
     *
     * Es un TERCER estado y no un error: una renovación firmada por
     * adelantado es lo normal, y con sólo «vigente / no vigente» la pantalla la
     * marcaba como si algo estuviera mal. Se vio en el navegador, no en la
     * suite.
     */
    public function aunNoEmpieza(?string $hoy = null): bool
    {
        return $this->vigente_desde->toDateString() > ($hoy ?? now()->toDateString());
    }

    /** ¿Ampara asignaciones HOY? Fecha y situación, las dos. */
    public function estaVigente(?string $hoy = null): bool
    {
        $dia = $hoy ?? now()->toDateString();

        return $this->situacion?->ampara_asignaciones === true
            && $this->vigente_desde->toDateString() <= $dia
            && ! $this->estaVencido($dia);
    }

    /** Cuántos días le quedan; null si no tiene fecha de término. */
    public function diasParaVencer(?CarbonInterface $hoy = null): ?int
    {
        if ($this->vigente_hasta === null) {
            return null;
        }

        return (int) ($hoy ?? now())->startOfDay()->diffInDays($this->vigente_hasta->startOfDay(), false);
    }

    /**
     * Los que amparan de verdad: situación que lo permite Y fecha dentro.
     *
     * Se define cruzando las dos condiciones porque una sola perdona el caso
     * que más engaña — el convenio «vigente» cuya fecha ya pasó—.
     */
    public function scopeVigentes(Builder $c, ?string $hoy = null): Builder
    {
        $dia = $hoy ?? now()->toDateString();

        return $c->whereHas('situacion', fn (Builder $s) => $s->where('ampara_asignaciones', true))
            ->whereDate('vigente_desde', '<=', $dia)
            ->where(fn (Builder $q) => $q->whereNull('vigente_hasta')->orWhereDate('vigente_hasta', '>=', $dia));
    }

    /**
     * Los que vencen dentro de N días y todavía amparan.
     *
     * Es lo que la alerta de vencimiento consulta.
     *
     * ── Los que NO tienen fecha de término quedan fuera SOLOS ─────────────
     * No vencen, así que avisar de ellos sería ruido permanente — y una alerta
     * siempre encendida se ignora. Llevaba un `whereNotNull('vigente_hasta')`
     * delante que decía eso mismo, y al mutarlo la prueba NO se cayó: medido,
     * una comparación de fecha contra NULL da NULL en MySQL y la fila se
     * descarta igual. Era código equivalente, así que se retiró en vez de
     * dejarlo como una segunda forma de decir lo mismo — la lección de
     * `$diseno->exists` en el diseñador del historial.
     */
    public function scopePorVencer(Builder $c, int $dias, ?string $hoy = null): Builder
    {
        $dia = $hoy ?? now()->toDateString();

        return $c->vigentes($dia)
            ->whereDate('vigente_hasta', '<=', now()->parse($dia)->addDays($dias)->toDateString());
    }
}
