<?php

declare(strict_types=1);

namespace App\Models\Reportes;

use App\Models\Concerns\TieneAuditoria;
use App\Models\Identidad\Persona;
use App\Models\Identidad\Rol;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Un reporte que se manda solo por correo.
 *
 * ── Cuelga de la VISTA ───────────────────────────────────────────────────
 * «Mándame la cartera» no es una instrucción; «mándame la cartera vencida del
 * campus norte con estas seis columnas» sí, y eso es lo que una vista guarda.
 *
 * ── Y CORRE con el rol guardado, que es toda la seguridad ────────────────
 * De madrugada no hay nadie con sesión abierta, así que no hay rol activo del
 * que sacar el alcance por campus. Se fija al programarla, y si el dueño lo
 * pierde la programación se SUSPENDE con su motivo — nunca corre con otro.
 */
class ProgramacionReporte extends Model
{
    use TieneAuditoria;

    protected $table = 'programaciones_reporte';

    public const DIARIA = 'diaria';

    public const SEMANAL = 'semanal';

    public const MENSUAL = 'mensual';

    /** Lo que salió de la última corrida. */
    public const OK = 'ok';

    public const VACIO = 'vacio';

    public const ERROR = 'error';

    protected $fillable = [
        'vista_id', 'nombre', 'persona_id', 'rol_id',
        'frecuencia', 'dia', 'hora', 'formato', 'activa',
        'suspendida_en', 'motivo_suspension',
        'ultima_corrida_en', 'ultimo_estado', 'ultimo_error',
    ];

    protected function casts(): array
    {
        return [
            'activa' => 'boolean',
            'dia' => 'integer',
            'suspendida_en' => 'datetime',
            'ultima_corrida_en' => 'datetime',
        ];
    }

    public function vista(): BelongsTo
    {
        return $this->belongsTo(VistaReporte::class, 'vista_id');
    }

    public function dueno(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'persona_id');
    }

    public function rol(): BelongsTo
    {
        return $this->belongsTo(Rol::class, 'rol_id');
    }

    public function destinatarios(): HasMany
    {
        return $this->hasMany(DestinatarioReporte::class, 'programacion_id');
    }

    /** Ni apagada a mano ni suspendida por el sistema. */
    public function scopeVivas(Builder $consulta): Builder
    {
        return $consulta->where('activa', true)->whereNull('suspendida_en');
    }

    /**
     * Si a esta hora le toca correr.
     *
     * ── Se compara con la hora ya PASADA, no con la exacta ───────────────
     * El despachador corre cada minuto en el mejor de los casos, y en el peor la
     * máquina estuvo apagada o el comando anterior se alargó. Exigir que el
     * minuto coincida haría que una programación se saltara el día entero por
     * llegar tarde treinta segundos — y nadie se enteraría, porque no falla:
     * simplemente no llega el correo.
     *
     * Lo que impide mandarlo dos veces no es la hora sino `ultima_corrida_en`:
     * ya corrió hoy, no vuelve a correr.
     */
    public function leTocaA(Carbon $momento): bool
    {
        if (! $this->activa || $this->suspendida_en !== null) {
            return false;
        }

        if (! $this->esSuDia($momento)) {
            return false;
        }

        if ($momento->format('H:i') < substr((string) $this->hora, 0, 5)) {
            return false;
        }

        return ! $this->yaCorrioEnEsteTurno($momento);
    }

    /** Si hoy es el día que le toca según su frecuencia. */
    private function esSuDia(Carbon $momento): bool
    {
        return match ($this->frecuencia) {
            self::DIARIA => true,
            // ISO: 1 lunes … 7 domingo, que es como lo elige la pantalla.
            self::SEMANAL => $this->dia === $momento->dayOfWeekIso,
            /*
             * El día del mes se topa a 28 al capturarlo: con 31 nunca correría
             * en febrero, y «el último día» es otra regla que nadie ha pedido.
             */
            self::MENSUAL => $this->dia === $momento->day,
            default => false,
        };
    }

    /**
     * Si ya corrió en el turno que le tocaba.
     *
     * Es lo que impide el correo repetido, y se mide por FRECUENCIA: la diaria
     * no repite el mismo día, la semanal no repite en la misma semana, la
     * mensual no repite en el mismo mes. Con un simple «ya corrió hoy», una
     * semanal que fallara el lunes reintentaría el martes — que es lo correcto—,
     * pero una diaria que corriera a las 23:59 volvería a correr a las 00:01.
     */
    private function yaCorrioEnEsteTurno(Carbon $momento): bool
    {
        if ($this->ultima_corrida_en === null) {
            return false;
        }

        return match ($this->frecuencia) {
            self::DIARIA => $this->ultima_corrida_en->isSameDay($momento),
            self::SEMANAL => $this->ultima_corrida_en->isSameDay($momento),
            self::MENSUAL => $this->ultima_corrida_en->isSameMonth($momento)
                && $this->ultima_corrida_en->year === $momento->year,
            default => true,
        };
    }

    /** Cómo se lee su periodicidad, para la pantalla y para el correo. */
    public function cuando(): string
    {
        $hora = substr((string) $this->hora, 0, 5);

        $dias = [1 => 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado', 'domingo'];

        return match ($this->frecuencia) {
            self::DIARIA => "Todos los días a las {$hora}",
            self::SEMANAL => 'Cada '.($dias[$this->dia] ?? '—')." a las {$hora}",
            self::MENSUAL => "El día {$this->dia} de cada mes a las {$hora}",
            default => $hora,
        };
    }
}
