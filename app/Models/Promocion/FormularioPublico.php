<?php

declare(strict_types=1);

namespace App\Models\Promocion;

use App\Models\Academico\Campus;
use App\Models\Academico\Oferta;
use App\Models\Admisiones\Asesor;
use App\Models\Admisiones\EtapaCrm;
use App\Models\Concerns\TieneAuditoria;
use App\Models\Formularios\Formulario;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * formularios_publicos (TENANT) — un formulario ofrecido en la web de la
 * escuela, sin sesión.
 *
 * Dos modos, que es la decisión del cliente:
 *  - `captacion`: el visitante deja sus datos y entra al CRM como prospecto.
 *    Promoción da el seguimiento.
 *  - `inscripcion`: además se le crea su cuenta para que continúe solo.
 */
class FormularioPublico extends Model
{
    use TieneAuditoria;

    public const MODO_CAPTACION = 'captacion';

    public const MODO_INSCRIPCION = 'inscripcion';

    protected $table = 'formularios_publicos';

    protected $attributes = [
        'modo' => self::MODO_CAPTACION,
        'activo' => true,
        'visitas' => 0,
        'envios' => 0,
    ];

    protected $fillable = [
        'formulario_id',
        'token',
        'nombre',
        'modo',
        'titulo',
        'bienvenida',
        'gracias',
        'origen_id',
        'etapa_crm_id',
        'campus_id',
        'oferta_id',
        'asesor_persona_id',
        'activo',
        'vigente_desde',
        'vigente_hasta',
    ];

    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
            'vigente_desde' => 'date',
            'vigente_hasta' => 'date',
        ];
    }

    /**
     * El token se genera solo. No se deja al capturista: uno elegido a mano
     * acaba siendo "inscripciones2026", que cualquiera adivina.
     */
    protected static function booted(): void
    {
        static::creating(function (self $publicacion): void {
            $publicacion->token ??= (string) Str::uuid();
        });
    }

    public function formulario(): BelongsTo
    {
        return $this->belongsTo(Formulario::class, 'formulario_id');
    }

    public function origen(): BelongsTo
    {
        return $this->belongsTo(OrigenAspirante::class, 'origen_id');
    }

    public function etapa(): BelongsTo
    {
        return $this->belongsTo(EtapaCrm::class, 'etapa_crm_id');
    }

    public function campus(): BelongsTo
    {
        return $this->belongsTo(Campus::class);
    }

    public function oferta(): BelongsTo
    {
        return $this->belongsTo(Oferta::class);
    }

    public function asesor(): BelongsTo
    {
        return $this->belongsTo(Asesor::class, 'asesor_persona_id', 'persona_id');
    }

    /**
     * Si hoy recibe. Se comprueba en CADA visita y en CADA envío: una campaña
     * que venció mientras alguien tenía la pestaña abierta no debe aceptar el
     * formulario que envíe después.
     */
    public function estaAbierto(?string $fecha = null): bool
    {
        if (! $this->activo) {
            return false;
        }

        $fecha ??= now()->toDateString();

        if ($this->vigente_desde !== null && $this->vigente_desde->toDateString() > $fecha) {
            return false;
        }

        return $this->vigente_hasta === null || $this->vigente_hasta->toDateString() >= $fecha;
    }

    public function permiteCuenta(): bool
    {
        return $this->modo === self::MODO_INSCRIPCION;
    }

    public function scopeAbiertos(Builder $query): Builder
    {
        $hoy = now()->toDateString();

        return $query->where('activo', true)
            ->where(fn (Builder $q) => $q->whereNull('vigente_desde')->orWhereDate('vigente_desde', '<=', $hoy))
            ->where(fn (Builder $q) => $q->whereNull('vigente_hasta')->orWhereDate('vigente_hasta', '>=', $hoy));
    }
}
