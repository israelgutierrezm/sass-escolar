<?php

declare(strict_types=1);

namespace App\Models\Reportes;

use App\Models\Concerns\TieneAuditoria;
use App\Models\Identidad\Persona;
use App\Models\Identidad\Rol;
use App\Models\Identidad\Usuario;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una forma guardada de ver un reporte: sus columnas, sus filtros y su orden.
 *
 * ── Guarda CONFIGURACIÓN, jamás filas ────────────────────────────────────
 * Es la regla que la hace segura de compartir. Al ejecutarla se rehace el
 * pipeline entero con el permiso, la faceta y el alcance por campus de QUIEN LA
 * EJECUTA: el coordinador del campus norte que abre una vista de dirección
 * general ve el norte, no lo que veía el dueño. Compartir una vista no comparte
 * datos.
 */
class VistaReporte extends Model
{
    use TieneAuditoria;

    protected $table = 'vistas_reporte';

    protected $fillable = [
        'reporte', 'nombre', 'descripcion', 'columnas', 'filtros',
        'orden_por', 'orden_dir', 'persona_id', 'rol_id', 'predeterminada',
    ];

    protected function casts(): array
    {
        return [
            'columnas' => 'array',
            'filtros' => 'array',
            'predeterminada' => 'boolean',
        ];
    }

    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class);
    }

    public function rol(): BelongsTo
    {
        return $this->belongsTo(Rol::class);
    }

    /**
     * Las que esta persona puede abrir: las suyas, las de su rol activo y las
     * de la escuela.
     *
     * Se filtra por el rol ACTIVO y no por todos los que tiene: una vista
     * compartida a control escolar no tiene por qué aparecerle a quien está
     * operando como docente en ese momento.
     */
    public function scopeVisiblesPara(Builder $c, Usuario $usuario): Builder
    {
        return $c->where(fn (Builder $q) => $q
            ->where('persona_id', $usuario->persona_id)
            ->orWhereNull('persona_id')
            ->orWhere('rol_id', $usuario->rol_activo_id));
    }

    /** ¿Puede esta persona modificarla? Sólo el dueño, o quien organiza. */
    public function laPuedeEditar(Usuario $usuario): bool
    {
        // Una vista de la ESCUELA (sin dueño) sólo la toca quien organiza: si
        // cualquiera pudiera, la vista que usa toda la dirección cambiaría sin
        // que nadie supiera quién.
        return $this->persona_id === null
            ? $usuario->can('gestionar-areas-reporte')
            : $this->persona_id === $usuario->persona_id;
    }
}
