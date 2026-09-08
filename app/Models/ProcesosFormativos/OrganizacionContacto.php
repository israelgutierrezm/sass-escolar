<?php

declare(strict_types=1);

namespace App\Models\ProcesosFormativos;

use App\Models\Concerns\TieneAuditoria;
use App\Models\Identidad\Persona;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * organizacion_contactos (TENANT) — con quién se habla en la receptora.
 *
 * ── UN solo lugar ──────────────────────────────────────────────────────────
 * Y no un `contacto_id` en la organización MÁS una tabla de «contactos
 * adicionales»: serían dos sitios donde buscar al mismo responsable y la duda
 * de si el principal aparece también en la tabla. Es la lección que dejó el
 * padrón de empleadores de la bolsa.
 *
 * ── Ser el CONTACTO y ser el SUPERVISOR son cosas distintas ────────────────
 * Quien firma el convenio en una dependencia rara vez es quien está al lado
 * del practicante todos los días, y el expediente apunta al segundo. Por eso
 * son dos banderas y no un tipo con dos valores: la misma persona puede ser las
 * dos cosas en una organización chica.
 *
 * ── `persona_id` es opcional a propósito ───────────────────────────────────
 * Exigir que el supervisor externo sea una `persona` de la escuela llenaría el
 * padrón de gente que ni estudia ni trabaja ahí. Cuando llegue su portal, será
 * esta columna la que lo haga posible sin cambiar nada más.
 */
class OrganizacionContacto extends Model
{
    use TieneAuditoria;

    protected $table = 'organizacion_contactos';

    protected $fillable = [
        'organizacion_id',
        'nombre',
        'cargo',
        'correo',
        'telefono',
        'es_principal',
        'es_supervisor',
        'persona_id',
        'invitado_en',
        'acceso_desde',
        'acceso_hasta',
        'acceso_revocado_en',
        'acceso_revocado_por',
    ];

    protected function casts(): array
    {
        return [
            'es_principal' => 'boolean',
            'es_supervisor' => 'boolean',
            'invitado_en' => 'datetime',
            'acceso_desde' => 'date',
            'acceso_hasta' => 'date',
            'acceso_revocado_en' => 'datetime',
            'acceso_revocado_por' => 'integer',
        ];
    }

    public function organizacion(): BelongsTo
    {
        return $this->belongsTo(OrganizacionReceptora::class, 'organizacion_id');
    }

    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'persona_id');
    }

    /** Los expedientes que este contacto supervisa. */
    public function expedientesSupervisados(): HasMany
    {
        return $this->hasMany(ExpedienteProceso::class, 'contacto_supervisor_id');
    }

    /**
     * ¿Tiene acceso VIGENTE al portal de supervisión, HOY?
     *
     * ── La misma regla, en SQL y en PHP ────────────────────────────────────
     * El alcance de los expedientes la usa como scope (`scopeConAccesoVigente`)
     * y como comprobación por fila (esta). Están escritas dos veces —una la
     * evalúa MySQL, otra PHP—, así que una prueba las CRUZA: si se separan, el
     * portal empezaría a mostrar u ocultar lo que no debe, sin fallar. Es la
     * defensa que este módulo ya usó para las columnas generadas.
     *
     * ── Fue INVITADO es la primera condición ───────────────────────────────
     * `invitado_en` en null significa que este contacto nunca recibió portal:
     * su `acceso_desde` sería null y sin esta guarda el rango «desde null =
     * siempre» le abriría la puerta a quien nadie invitó.
     */
    public function accesoVigente(): bool
    {
        $hoy = now()->toDateString();

        return $this->invitado_en !== null
            && $this->acceso_revocado_en === null
            && ($this->acceso_desde === null || $this->acceso_desde->toDateString() <= $hoy)
            && ($this->acceso_hasta === null || $this->acceso_hasta->toDateString() >= $hoy);
    }

    /**
     * El estado del acceso, en una palabra, para la pantalla del administrador.
     * Deriva de las mismas columnas que `accesoVigente()`, así que nunca puede
     * decir «vigente» sobre lo que el alcance trata como vencido.
     */
    public function estadoAcceso(): string
    {
        if ($this->invitado_en === null) {
            return 'sin_invitar';
        }

        if ($this->acceso_revocado_en !== null) {
            return 'revocado';
        }

        $hoy = now()->toDateString();

        if ($this->acceso_desde !== null && $this->acceso_desde->toDateString() > $hoy) {
            return 'programado';
        }

        if ($this->acceso_hasta !== null && $this->acceso_hasta->toDateString() < $hoy) {
            return 'vencido';
        }

        return 'vigente';
    }

    /** @param  Builder<self>  $query */
    public function scopeConAccesoVigente(Builder $query): Builder
    {
        $hoy = now()->toDateString();

        return $query
            ->whereNotNull('invitado_en')
            ->whereNull('acceso_revocado_en')
            ->where(fn (Builder $w) => $w->whereNull('acceso_desde')->orWhere('acceso_desde', '<=', $hoy))
            ->where(fn (Builder $w) => $w->whereNull('acceso_hasta')->orWhere('acceso_hasta', '>=', $hoy));
    }
}
