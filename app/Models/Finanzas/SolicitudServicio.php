<?php

declare(strict_types=1);

namespace App\Models\Finanzas;

use App\Models\Admisiones\MatriculaOferta;
use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una solicitud de servicio de un alumno.
 *
 * El estado guardado es el del TRÁMITE. Si falta pagar no se guarda: se le
 * pregunta al adeudo cada vez —ver `esperandoPago`—, porque el adeudo es el
 * único sitio donde vive la verdad sobre lo cobrado.
 */
class SolicitudServicio extends Model
{
    use TieneAuditoria;

    /** Pedida y todavía sin cerrar. */
    public const ESTADO_PEDIDA = 'pedida';

    /** La escuela la resolvió y entregó. */
    public const ESTADO_ATENDIDA = 'atendida';

    /** La escuela la negó, con su motivo en `respuesta`. */
    public const ESTADO_RECHAZADA = 'rechazada';

    /** El alumno se arrepintió antes de que la atendieran. */
    public const ESTADO_CANCELADA = 'cancelada';

    protected $table = 'solicitudes_servicio';

    protected $fillable = [
        'servicio_id',
        'matricula_oferta_id',
        'adeudo_id',
        'estado',
        'nota_alumno',
        'respuesta',
        'atendida_por',
        'atendida_en',
    ];

    protected function casts(): array
    {
        return ['atendida_en' => 'datetime'];
    }

    public function servicio(): BelongsTo
    {
        return $this->belongsTo(Servicio::class, 'servicio_id');
    }

    public function matricula(): BelongsTo
    {
        return $this->belongsTo(MatriculaOferta::class, 'matricula_oferta_id');
    }

    public function adeudo(): BelongsTo
    {
        return $this->belongsTo(Adeudo::class, 'adeudo_id');
    }

    /**
     * Si todavía falta dinero por entrar.
     *
     * Se usa el MISMO criterio que `Adeudo::porCobrar`, que es el que define en
     * todo el sistema qué pesa en un estado de cuenta: sólo lo pendiente y lo
     * parcial. Un adeudo condonado o cancelado ya no se le cobra a nadie, así
     * que tampoco puede detener el trámite —si lo detuviera, condonar el cargo
     * de una constancia dejaría al alumno esperando para siempre una constancia
     * que la escuela ya decidió regalarle.
     *
     * Y además el saldo, no sólo el estatus: un adeudo «parcial» lleva un abono
     * encima pero a medio pagar el trámite sigue sin poder arrancar.
     */
    public function esperandoPago(): bool
    {
        if ($this->adeudo === null) {
            return false;
        }

        $pesa = in_array(
            $this->adeudo->estatus,
            [Adeudo::ESTATUS_PENDIENTE, Adeudo::ESTATUS_PARCIAL],
            true,
        );

        return $pesa && $this->adeudo->saldo() > 0;
    }

    /** Lista para que la escuela la trabaje: pedida y sin nada pendiente de cobro. */
    public function enProceso(): bool
    {
        return $this->estado === self::ESTADO_PEDIDA && ! $this->esperandoPago();
    }

    /** Se puede cancelar mientras nadie la haya cerrado. */
    public function esCancelable(): bool
    {
        return $this->estado === self::ESTADO_PEDIDA;
    }

    public function scopeAbiertas(Builder $consulta): Builder
    {
        return $consulta->where('estado', self::ESTADO_PEDIDA);
    }
}
