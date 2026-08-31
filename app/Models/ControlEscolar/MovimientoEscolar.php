<?php

declare(strict_types=1);

namespace App\Models\ControlEscolar;

use App\Models\Academico\Oferta;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\Admisiones\SituacionAlumno;
use App\Models\Concerns\TieneAuditoria;
use App\Models\Identidad\Usuario;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * movimientos_escolares (TENANT) — un hecho de la trayectoria de una matrícula.
 *
 * ── Es historia, no estado ─────────────────────────────────────────────────
 * El estado vigente lo siguen diciendo las tablas de siempre:
 * `matricula_oferta.situacion_id` dice cómo está HOY, y `inscripcion` en qué
 * cursa. Este modelo dice qué PASÓ y cuándo. No se reconstruye la situación
 * actual recorriendo movimientos: serían dos verdades sobre lo mismo, y el día
 * que discrepen nadie sabría a cuál creerle.
 *
 * ── Inmutable ──────────────────────────────────────────────────────────────
 * No hay ruta que lo edite ni que lo borre. Un error se enmienda registrando
 * otro movimiento que lo corrige, y los dos se conservan.
 */
class MovimientoEscolar extends Model
{
    use TieneAuditoria;

    protected $table = 'movimientos_escolares';

    protected $fillable = [
        'matricula_oferta_id', 'tipo_id', 'fecha_efectiva', 'ciclo_id',
        'situacion_anterior_id', 'situacion_nueva_id',
        'oferta_anterior_id', 'oferta_nueva_id',
        'grupo_anterior_id', 'grupo_nuevo_id',
        'periodo_anterior', 'periodo_nuevo',
        'motivo', 'observaciones', 'origen', 'referencia', 'corrige_movimiento_id',
    ];

    /**
     * De dónde salió.
     *
     * Distinguirlo importa para auditar: sin esto, un movimiento capturado a
     * mano y uno emitido por la conversión de un aspirante se leen igual, y no
     * hay forma de saber si el sistema está registrando lo que debe.
     */
    public const ORIGEN_MANUAL = 'manual';

    public const ORIGEN_CONVERSION = 'conversion_aspirante';

    public const ORIGEN_MATRICULACION = 'matriculacion';

    public const ORIGEN_BAJA = 'baja';

    public const ORIGEN_REINGRESO = 'reingreso';

    public const ORIGEN_TITULACION = 'titulacion';

    protected function casts(): array
    {
        return ['fecha_efectiva' => 'date'];
    }

    public function matricula(): BelongsTo
    {
        return $this->belongsTo(MatriculaOferta::class, 'matricula_oferta_id');
    }

    public function tipo(): BelongsTo
    {
        return $this->belongsTo(TipoMovimientoEscolar::class, 'tipo_id');
    }

    public function ciclo(): BelongsTo
    {
        return $this->belongsTo(Ciclo::class, 'ciclo_id');
    }

    public function situacionAnterior(): BelongsTo
    {
        return $this->belongsTo(SituacionAlumno::class, 'situacion_anterior_id');
    }

    public function situacionNueva(): BelongsTo
    {
        return $this->belongsTo(SituacionAlumno::class, 'situacion_nueva_id');
    }

    public function ofertaAnterior(): BelongsTo
    {
        return $this->belongsTo(Oferta::class, 'oferta_anterior_id');
    }

    public function ofertaNueva(): BelongsTo
    {
        return $this->belongsTo(Oferta::class, 'oferta_nueva_id');
    }

    public function grupoAnterior(): BelongsTo
    {
        return $this->belongsTo(Grupo::class, 'grupo_anterior_id');
    }

    public function grupoNuevo(): BelongsTo
    {
        return $this->belongsTo(Grupo::class, 'grupo_nuevo_id');
    }

    /** Quién lo registró. Sale de la auditoría, que ya lo guarda. */
    public function registro(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'created_by');
    }

    public function corrige(): BelongsTo
    {
        return $this->belongsTo(self::class, 'corrige_movimiento_id');
    }

    /**
     * La trayectoria de una matrícula, en orden.
     *
     * Por `fecha_efectiva` y NO por `created_at`: una baja del 3 de junio
     * capturada el 10 va antes que una reinscripción del 5. El id desempata
     * para que dos movimientos del mismo día salgan siempre en el mismo orden.
     */
    public function scopeDe(Builder $q, int $matriculaId): Builder
    {
        return $q->where('matricula_oferta_id', $matriculaId)
            ->orderByDesc('fecha_efectiva')
            ->orderByDesc('id');
    }

    /** ¿Lo emitió un proceso, o lo capturó una persona? */
    public function esAutomatico(): bool
    {
        return $this->origen !== self::ORIGEN_MANUAL;
    }
}
