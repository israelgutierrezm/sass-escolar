<?php

declare(strict_types=1);

namespace App\Models\ProcesosFormativos;

use App\Models\Concerns\TieneAuditoria;
use App\Models\Landlord\EntidadFederativa;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * organizaciones_receptoras (TENANT) — dónde presta su servicio un alumno.
 *
 * ── Padrón propio, y no el de EMPLEADORES ──────────────────────────────────
 * `empresas` es de la bolsa de trabajo: vive detrás de un módulo apagable y su
 * situación incluye «vetada», que es un concepto de contratación. Una receptora
 * suele ser una dependencia, un hospital, una escuela o una asociación civil.
 *
 * ── Se APAGA con su situación, no se borra ─────────────────────────────────
 * Sus expedientes históricos son la prueba de dónde estuvo alguien, y borrarla
 * se los llevaría. Por eso la pantalla no tiene botón de eliminar y lo que hay
 * es una situación que deja de aceptar asignaciones.
 *
 * ── Y «acepta» se lee por la BANDERA ───────────────────────────────────────
 * `situacion->acepta_asignaciones`, nunca `clave === 'activa'`: una escuela que
 * agregue «en trámite» o «con convenio en firma» decide ella misma de qué lado
 * cae, y ninguna de las dos se llama «activa».
 */
class OrganizacionReceptora extends Model
{
    use TieneAuditoria;

    protected $table = 'organizaciones_receptoras';

    protected $fillable = [
        'razon_social',
        'nombre_comercial',
        'rfc',
        'sector_id',
        'tipo_id',
        'situacion_id',
        'calle',
        'colonia',
        'municipio',
        'entidad_federativa_id',
        'codigo_postal',
        'representante',
        'sitio_web',
        'telefono',
        'correo',
        'cupo_total',
        'notas',
    ];

    protected function casts(): array
    {
        return ['cupo_total' => 'integer'];
    }

    public function sector(): BelongsTo
    {
        return $this->belongsTo(SectorOrganizacion::class, 'sector_id');
    }

    public function tipo(): BelongsTo
    {
        return $this->belongsTo(TipoOrganizacion::class, 'tipo_id');
    }

    public function situacion(): BelongsTo
    {
        return $this->belongsTo(SituacionOrganizacion::class, 'situacion_id');
    }

    /**
     * La entidad federativa vive en la base CENTRAL.
     *
     * Sin foránea —regla del proyecto para los catálogos universales—; la
     * relación resuelve porque el modelo landlord usa `CentralConnection`.
     */
    public function entidad(): BelongsTo
    {
        return $this->belongsTo(EntidadFederativa::class, 'entidad_federativa_id');
    }

    public function contactos(): HasMany
    {
        return $this->hasMany(OrganizacionContacto::class, 'organizacion_id');
    }

    public function alcances(): HasMany
    {
        return $this->hasMany(OrganizacionAlcance::class, 'organizacion_id');
    }

    public function convenios(): HasMany
    {
        return $this->hasMany(ConvenioFormativo::class, 'organizacion_id');
    }

    public function plazas(): HasMany
    {
        return $this->hasMany(PlazaProceso::class, 'organizacion_id');
    }

    /** Con quién se habla, si alguien lo marcó. */
    public function contactoPrincipal(): HasMany
    {
        return $this->contactos()->where('es_principal', true);
    }

    /**
     * Las que hoy pueden recibir a alguien.
     *
     * Se define por la BANDERA de la situación y no exigiendo «activa»: una
     * escuela que renombre su catálogo seguiría funcionando, y una con la
     * situación en un valor nuevo no desaparecería en silencio.
     */
    public function scopeQueReciben(Builder $c): Builder
    {
        return $c->whereHas('situacion', fn (Builder $s) => $s->where('acepta_asignaciones', true));
    }

    /**
     * ¿Está autorizada para este campus, programa y tipo de proceso?
     *
     * **Sin alcances declarados, alcanza a TODO.** La mayoría de las receptoras
     * sirven para cualquier programa, y exigir al menos una fila obligaría a
     * palomear veinte cada vez. Es la misma decisión que los convenios de
     * movilidad y las vacantes de la bolsa; la pantalla lo dice con palabras,
     * porque un hueco se lee como captura incompleta.
     *
     * Cada fila de alcance es un permiso independiente: basta que UNA case.
     * Dentro de una fila, lo declarado tiene que coincidir y lo que está en
     * null no acota.
     */
    public function alcanzaA(?int $campusId, ?int $programaId, ?int $tipoProcesoId): bool
    {
        $alcances = $this->relationLoaded('alcances') ? $this->alcances : $this->alcances()->get();

        if ($alcances->isEmpty()) {
            return true;
        }

        return $alcances->contains(
            fn (OrganizacionAlcance $a) => ($a->campus_id === null || $a->campus_id === $campusId)
                && ($a->programa_academico_id === null || $a->programa_academico_id === $programaId)
                && ($a->tipo_proceso_id === null || $a->tipo_proceso_id === $tipoProcesoId)
        );
    }

    /** Cómo se le llama en pantalla: el comercial si lo hay, si no la razón social. */
    public function comoSeLeConoce(): string
    {
        return $this->nombre_comercial ?: $this->razon_social;
    }
}
