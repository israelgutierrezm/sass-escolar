<?php

declare(strict_types=1);

namespace App\Models\ControlEscolar;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ventanas_captura (TENANT) — el calendario de captura, por parcial.
 *
 * A diferencia de `ciclos.captura_calif_hasta` —que solo marca el acta como
 * extemporánea— esta ventana sí impide capturar fuera de fecha.
 */
class VentanaCaptura extends Model
{
    use TieneAuditoria;

    protected $table = 'ventanas_captura';

    protected $fillable = [
        'ciclo_id',
        'parcial',
        'nombre',
        'desde',
        'hasta',
        'activa',
    ];

    protected function casts(): array
    {
        return [
            'desde' => 'date',
            'hasta' => 'date',
            'activa' => 'boolean',
        ];
    }

    public function ciclo(): BelongsTo
    {
        return $this->belongsTo(Ciclo::class, 'ciclo_id');
    }

    public function excepciones(): HasMany
    {
        return $this->hasMany(ExcepcionCaptura::class, 'ventana_id');
    }

    /** ¿Está abierta hoy (o en la fecha dada)? */
    public function estaAbierta(?string $fecha = null): bool
    {
        if (! $this->activa) {
            return false;
        }

        $fecha ??= now()->toDateString();

        return $fecha >= $this->desde->toDateString()
            && $fecha <= $this->hasta->toDateString();
    }

    /** Nombre legible del corte, para los mensajes al docente. */
    public function etiqueta(): string
    {
        if ($this->nombre !== null && $this->nombre !== '') {
            return $this->nombre;
        }

        return $this->parcial === null ? 'rubros del curso' : "parcial {$this->parcial}";
    }

    public function scopeActivas(Builder $query): Builder
    {
        return $query->where('activa', true);
    }
}
