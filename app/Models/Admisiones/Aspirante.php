<?php

declare(strict_types=1);

namespace App\Models\Admisiones;

use App\Models\Academico\Campus;
use App\Models\Academico\Oferta;
use App\Models\Captacion\OrigenAspirante;
use App\Models\Captacion\SeguimientoAspirante;
use App\Models\Concerns\TieneAuditoria;
use App\Models\Identidad\Persona;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * aspirantes (TENANT) — prospecto en el embudo de admisión.
 */
class Aspirante extends Model
{
    use TieneAuditoria;

    protected $table = 'aspirantes';

    protected $fillable = [
        'persona_id',
        'oferta_interes_id',
        'campus_id',
        'clave_aspirante',
        'descartado_en',
        'motivo_descarte',
        'paso',
        'acepto_terminos',
        'info_personal_completa',
        'validado_admin',
        'origen',
        'origen_id',
        'ciclo_ingreso_id',
        'etapa_crm_id',
    ];

    protected function casts(): array
    {
        return [
            'acepto_terminos' => 'boolean',
            'info_personal_completa' => 'boolean',
            'validado_admin' => 'boolean',
            'descartado_en' => 'datetime',
        ];
    }

    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class);
    }

    /**
     * En qué punto del embudo va. `etapas_crm` estaba sembrada desde la Fase 1
     * y no la usaba nadie: sin esta columna el embudo era un catálogo, no un
     * dato, y no se podía saber cuántos se caen entre una etapa y otra.
     */
    /**
     * Dónde está parado en el embudo, incluso si la escuela RETIRÓ esa etapa.
     *
     * ── Por qué `withTrashed()` ───────────────────────────────────────────
     * Apagar una etapa la saca del embudo para lo que venga, pero NO mueve a
     * quien ya estaba parado ahí. Sin esto la relación devuelve null y el
     * prospecto aparece «sin etapa» en el tablero y en los reportes: se pierde
     * el dato por editar un catálogo, y encima el filtro por etapa tampoco lo
     * alcanza —sus opciones salen del catálogo vivo—, así que queda invisible
     * por los dos lados.
     *
     * En el demo la etapa 4 «En evaluación» está dada de baja, así que el caso
     * no es hipotético: hoy no muerde sólo porque nadie está parado en ella.
     *
     * Es la misma decisión —y ahora la misma escritura— que
     * {@see SeguimientoAspirante::etapa()}, que ya la
     * llevaba. Que dos lecturas del mismo catálogo se comportaran distinto era
     * la incongruencia.
     */
    public function etapa(): BelongsTo
    {
        return $this->belongsTo(EtapaCrm::class, 'etapa_crm_id')->withTrashed();
    }

    public function origenAspirante(): BelongsTo
    {
        return $this->belongsTo(OrigenAspirante::class, 'origen_id');
    }

    /** La bitácora de contacto, del más reciente al más viejo. */
    public function seguimientos(): HasMany
    {
        return $this->hasMany(SeguimientoAspirante::class, 'aspirante_id')
            ->orderByDesc('momento')
            ->orderByDesc('id');
    }

    public function ofertaInteres(): BelongsTo
    {
        return $this->belongsTo(Oferta::class, 'oferta_interes_id');
    }

    public function campus(): BelongsTo
    {
        return $this->belongsTo(Campus::class);
    }

    /**
     * ── El DESENLACE se deriva; ya no hay columna de situación ────────────
     *
     * `situaciones_aspirante` duplicaba al embudo: «Aceptado» vivía en los dos
     * catálogos y podían contradecirse. De sus cinco valores, tres eran puntos
     * del recorrido —que dice la etapa— y los dos que informaban de verdad se
     * resuelven mejor por otro lado.
     */

    /**
     * ¿Ya se inscribió?
     *
     * Tener matrícula PARA SU OFERTA DE INTERÉS. Es más cierto que un campo
     * —con él se podía estar «Inscrito» sin matrícula y nada se quejaba— y es
     * la misma pareja (persona, oferta) que `ConvertidorAspirante` comprueba
     * antes de convertir.
     *
     * Por la OFERTA y no por «tiene alguna matrícula»: quien ya estudia una
     * programa académico y se postula a una segunda sigue siendo un prospecto abierto para
     * ésa, y darlo por inscrito lo sacaría del embudo desde el primer día.
     */
    public function estaInscrito(): bool
    {
        /*
         * Si la consulta ya lo resolvió con `withExists`, se le cree: volver a
         * preguntarlo por fila es exactamente la consulta N+1 que el listado
         * viene a evitar.
         */
        if (array_key_exists('ya_inscrito', $this->attributes)) {
            return (bool) $this->attributes['ya_inscrito'];
        }

        if ($this->persona_id === null || $this->oferta_interes_id === null) {
            return false;
        }

        return MatriculaOferta::query()
            ->where('persona_id', $this->persona_id)
            ->where('oferta_id', $this->oferta_interes_id)
            ->exists();
    }

    /** Se dio por perdido. Con fecha, y casi siempre con motivo. */
    public function estaDescartado(): bool
    {
        return $this->descartado_en !== null;
    }

    /**
     * En qué acabó, para pintarlo.
     *
     * Inscrito gana sobre descartado: si acabó matriculándose, lo que pasara
     * antes es historia del recorrido y lo dice su bitácora de seguimientos.
     */
    public function desenlace(): string
    {
        if ($this->estaInscrito()) {
            return 'inscrito';
        }

        return $this->estaDescartado() ? 'descartado' : 'abierto';
    }

    /**
     * Por qué NO se puede descartar, o null si se puede.
     *
     * A quien ya se inscribió no se le descarta: su matrícula existe y el
     * descarte diría que se perdió un prospecto que en realidad se ganó.
     */
    public function motivoParaNoDescartar(): ?string
    {
        if ($this->estaInscrito()) {
            return 'Ya está inscrito: no se puede descartar a quien ya tiene matrícula.';
        }

        return $this->estaDescartado() ? 'Ya estaba descartado.' : null;
    }

    /** Los que siguen vivos: ni descartados ni inscritos. */
    public function scopeAbiertos(Builder $consulta): Builder
    {
        return $consulta->whereNull('descartado_en')->whereDoesntHave('matriculaDeSuOferta');
    }

    public function scopeDescartados(Builder $consulta): Builder
    {
        return $consulta->whereNotNull('descartado_en')->whereDoesntHave('matriculaDeSuOferta');
    }

    public function scopeInscritos(Builder $consulta): Builder
    {
        return $consulta->whereHas('matriculaDeSuOferta');
    }

    /**
     * La matrícula que corresponde a SU oferta de interés.
     *
     * Existe para poder filtrar en SQL —los scopes de arriba— sin traerse a
     * todos los aspirantes a memoria. `whereColumn` es lo que ata la oferta de
     * la matrícula a la de interés del aspirante.
     *
     * **Sólo sirve CORRELACIONADA**: `whereHas`, `whereDoesntHave`,
     * `withExists`. Precargarla con `with()` revienta —«Unknown column
     * aspirantes.oferta_interes_id»—, porque ahí la relación se consulta sola y
     * la tabla del padre no está en el FROM. Para el listado se usa
     * `withExists('matriculaDeSuOferta as ya_inscrito')`, que es una
     * subconsulta por fila y no una consulta por aspirante.
     */
    public function matriculaDeSuOferta(): HasMany
    {
        return $this->hasMany(MatriculaOferta::class, 'persona_id', 'persona_id')
            ->whereColumn('matricula_oferta.oferta_id', 'aspirantes.oferta_interes_id');
    }

    /** Asesores comerciales asignados. */
    public function asesores(): BelongsToMany
    {
        return $this->belongsToMany(Asesor::class, 'aspirante_asesor', 'aspirante_id', 'persona_id')
            // `titular` dice CUÁL de ellos responde por el prospecto. Sin eso
            // no se sabe a quién pagarle la comisión cuando hay dos asesores
            // encima del mismo aspirante.
            ->withPivot('titular')
            ->withTimestamps();
    }

    /** Tutores de admisión asignados. */
    public function tutores(): BelongsToMany
    {
        return $this->belongsToMany(TutorCrm::class, 'aspirante_tutor_crm', 'aspirante_id', 'persona_id')
            ->withTimestamps();
    }

    /** Descuentos de admisión otorgados. */
    public function descuentos_admision(): BelongsToMany
    {
        return $this->belongsToMany(DescuentoAdmision::class, 'aspirante_descuento_admision', 'aspirante_id', 'descuento_admision_id')
            ->withTimestamps();
    }

    /** Documentos entregados en el expediente de admisión. */
    public function expedienteDocumentos(): HasMany
    {
        return $this->hasMany(ExpedienteDocumento::class, 'aspirante_id');
    }
}
