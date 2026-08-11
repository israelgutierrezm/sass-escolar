<?php

declare(strict_types=1);

namespace App\Models\Promocion;

use App\Models\Admisiones\Aspirante;
use App\Models\Admisiones\EtapaCrm;
use App\Models\Concerns\TieneAuditoria;
use App\Models\Identidad\Persona;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * seguimientos_aspirante (TENANT) — la bitácora de contacto y la agenda.
 *
 * Es lo que convierte una lista de nombres en un CRM. Una entrada es un
 * contacto en algún punto de su vida:
 *
 *   AGENDADO ──► REALIZADO   (se hizo, y se dice cómo fue)
 *            └─► CANCELADO   (ya no se va a hacer, y se dice por qué)
 *
 * Un contacto que se registra después de ocurrido nace REALIZADO y no pasa por
 * la agenda. Son la misma tabla a propósito: una llamada agendada y una llamada
 * hecha no son dos cosas, son la misma en dos momentos, y separarlas obligaría
 * a volver a mezclarlas en la pantalla —que es donde se lee el historial—.
 *
 * ── Qué se puede editar y qué no ──────────────────────────────────────────
 * Lo CERRADO no se reescribe: un contacto ocurrió, y corregir la nota después
 * no cambia que ocurrió. Lo AGENDADO sí cambia, porque es un plan: se cumple,
 * se cancela o se mueve de fecha. Nada más.
 *
 * `etapa_crm_id` se congela al registrarlo, no se lee en vivo: es lo que
 * permite medir cuánto tardó un prospecto en pasar de una etapa a la siguiente.
 */
class SeguimientoAspirante extends Model
{
    use TieneAuditoria;

    /** Se quedó de hacer y todavía no se hace. */
    public const AGENDADO = 'agendado';

    /** Se hizo. Lleva resultado y, si hubo, la respuesta. */
    public const REALIZADO = 'realizado';

    /** Ya no se va a hacer. Se conserva: intentarlo y desistir es información. */
    public const CANCELADO = 'cancelado';

    protected $table = 'seguimientos_aspirante';

    protected $fillable = [
        'aspirante_id',
        'tipo_id',
        'persona_id',
        'etapa_crm_id',
        'nota',
        'proximo_contacto',
        'programado_para',
        'estatus',
        'resultado_id',
        'respuesta',
        'cerrado_por',
        'cerrado_en',
        'momento',
    ];

    protected function casts(): array
    {
        return [
            'proximo_contacto' => 'date',
            'programado_para' => 'datetime',
            'momento' => 'datetime',
            'cerrado_en' => 'datetime',
        ];
    }

    public function aspirante(): BelongsTo
    {
        return $this->belongsTo(Aspirante::class);
    }

    public function tipo(): BelongsTo
    {
        return $this->belongsTo(TipoSeguimiento::class, 'tipo_id');
    }

    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class);
    }

    public function etapa(): BelongsTo
    {
        return $this->belongsTo(EtapaCrm::class, 'etapa_crm_id');
    }

    public function resultado(): BelongsTo
    {
        return $this->belongsTo(ResultadoSeguimiento::class, 'resultado_id');
    }

    /** Quién la cerró. Puede no ser quien la tenía agendada. */
    public function cerradaPor(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'cerrado_por');
    }

    /** Lo que ya venció o vence hoy: el tablero de "qué me toca". */
    public function scopePendientes(Builder $query, ?string $fecha = null): Builder
    {
        $limite = $fecha ?? now()->toDateString();

        /*
         * Cuenta lo AGENDADO por su fecha, y además lo que quedó marcado con un
         * próximo contacto suelto.
         *
         * Lo segundo es la forma vieja —una fecha sin tarea— y sigue viva en lo
         * capturado antes de que existiera la agenda: ignorarla escondería
         * pendientes reales del tablero el día que se publique esto.
         */
        return $query->where(function (Builder $q) use ($limite) {
            $q->where(fn (Builder $agenda) => $agenda
                ->where('estatus', self::AGENDADO)
                ->whereNotNull('programado_para')
                ->whereDate('programado_para', '<=', $limite))
                ->orWhere(fn (Builder $viejo) => $viejo
                    ->where('estatus', self::REALIZADO)
                    ->whereNotNull('proximo_contacto')
                    ->whereDate('proximo_contacto', '<=', $limite));
        });
    }

    /** Lo agendado que sigue abierto, sin importar la fecha. */
    public function scopeAgendadas(Builder $query): Builder
    {
        return $query->where('estatus', self::AGENDADO);
    }

    /** ¿Está abierta y ya se le pasó la hora? */
    public function estaVencida(?string $fecha = null): bool
    {
        return $this->estatus === self::AGENDADO
            && $this->programado_para !== null
            && $this->programado_para->toDateString() < ($fecha ?? now()->toDateString());
    }
}
