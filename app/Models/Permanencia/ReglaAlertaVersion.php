<?php

declare(strict_types=1);

namespace App\Models\Permanencia;

use App\Models\Concerns\TieneAuditoria;
use App\Models\Identidad\Rol;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * reglas_alerta_versiones (TENANT) — qué mide una regla y con qué umbral.
 *
 * ── Por qué se versiona, y no es simetría con las reglas formativas ────────
 * La alerta guarda `regla_version_id`, así que dentro de dos años se puede
 * contestar «con qué umbral se generó esto» aunque la regla haya cambiado tres
 * veces. Sin versiones, mover el umbral de 80 a 75 reescribiría la historia de
 * todas las alertas que se generaron con el anterior — y la primera pregunta
 * que hace alguien al revisar un caso viejo es exactamente ésa.
 *
 * Por eso **cambiar una regla no toca las alertas abiertas**: las existentes
 * conservan su versión y las nuevas usan la que rige. La que se quedó huérfana
 * porque su versión se retiró se marca OBSOLETA, no resuelta: nadie arregló
 * nada.
 *
 * ── Las tres cosas que se confunden, y aquí van separadas ──────────────────
 *  - **Ventana**: sobre qué periodo se mide.
 *  - **Cobertura mínima**: cuántos datos hacen falta para atreverse a opinar.
 *  - **Enfriamiento**: tras CERRARSE, cuántos días no se vuelve a levantar.
 *
 * La deduplicación es una cuarta y no vive aquí: la sostiene el índice único de
 * la alerta mientras está abierta.
 */
class ReglaAlertaVersion extends Model
{
    use TieneAuditoria;

    protected $table = 'reglas_alerta_versiones';

    /** Los comparadores que se aceptan. Lista cerrada: se pegan a una comparación. */
    public const COMPARADORES = ['>=', '>', '<=', '<', '==', '!='];

    /** De dónde sale el umbral. */
    public const FUENTE_FIJA = 'fijo';

    public const FUENTE_PLAN = 'plan';

    /** Sobre qué periodo se mide. */
    public const VENTANAS = ['ciclo', 'ultimos_dias', 'desde_inicio'];

    /**
     * Qué tan grave, de menos a más.
     *
     * `informativo` existe y no sobra: hay señales que no piden intervención y
     * sí conviene que estén a la vista del tutor cuando abra el expediente por
     * otra cosa. Sin ese escalón, todo lo que se quiere registrar tiene que
     * entrar como «bajo» y la palabra deja de significar nada.
     */
    public const SEVERIDADES = ['informativo', 'bajo', 'medio', 'alto', 'critico'];

    protected $attributes = [
        'umbral_fuente' => self::FUENTE_FIJA,
        'ventana_tipo' => 'ciclo',
        'cobertura_minima' => 0,
        'severidad' => 'bajo',
        'peso' => 1,
        'frecuencia' => 'diaria',
        'cooldown_dias' => 14,
        'avisa_al_alumno' => false,
        'avisa_a_la_escuela' => false,
    ];

    protected $fillable = [
        'regla_id',
        'version',
        'vigente_desde',
        'vigente_hasta',
        'metrica',
        'comparador',
        'umbral',
        'umbral_fuente',
        'ventana_tipo',
        'ventana_valor',
        'cobertura_minima',
        'severidad',
        'peso',
        'frecuencia',
        'cooldown_dias',
        'sla_horas',
        'responsable_rol_id',
        'avisa_al_alumno',
        'avisa_a_la_escuela',
        'plantilla_aviso',
        'notas',
    ];

    protected function casts(): array
    {
        return [
            'vigente_desde' => 'date',
            'vigente_hasta' => 'date',
            'umbral' => 'float',
            'ventana_valor' => 'integer',
            'cobertura_minima' => 'integer',
            'peso' => 'integer',
            'cooldown_dias' => 'integer',
            'sla_horas' => 'integer',
            'version' => 'integer',
            'avisa_al_alumno' => 'boolean',
            'avisa_a_la_escuela' => 'boolean',
        ];
    }

    public function regla(): BelongsTo
    {
        return $this->belongsTo(ReglaAlerta::class, 'regla_id');
    }

    public function responsableRol(): BelongsTo
    {
        return $this->belongsTo(Rol::class, 'responsable_rol_id');
    }

    /** ¿Rige este día? Las fechas nulas no acotan por su lado. */
    public function rigeEl(string $dia): bool
    {
        if ($this->vigente_desde !== null && $this->vigente_desde->toDateString() > $dia) {
            return false;
        }

        return $this->vigente_hasta === null || $this->vigente_hasta->toDateString() >= $dia;
    }

    /**
     * ¿El valor observado cruza el umbral?
     *
     * La comparación vive AQUÍ y en un solo sitio: el motor la usa para
     * decidir y la pantalla para explicar. Escrita dos veces, la explicación
     * diría una cosa y la alerta otra, y aquí eso significa que alguien
     * interviene sobre un alumno por una razón que el sistema no puede
     * sostener.
     *
     * Un umbral nulo **no dispara nunca**, y es el lado correcto: una regla a
     * la que le falta el número está mal capturada, y en la duda no se molesta
     * a nadie. La pantalla lo impide al guardar; esto es la red de abajo.
     */
    public function cruza(?float $observado, ?float $umbral = null): bool
    {
        $limite = $umbral ?? $this->umbral;

        if ($observado === null || $limite === null) {
            return false;
        }

        return match ($this->comparador) {
            '>=' => $observado >= $limite,
            '>' => $observado > $limite,
            '<=' => $observado <= $limite,
            '<' => $observado < $limite,
            '==' => abs($observado - $limite) < 0.0001,
            '!=' => abs($observado - $limite) >= 0.0001,
            default => false,
        };
    }

    /**
     * Cómo se lee la condición, en una frase.
     *
     * Es lo que la alerta enseña como «por qué se generó». Se arma de los mismos
     * campos con los que se decide, así que no puede decir algo distinto de lo
     * que pasó — que es la diferencia entre una explicación y una leyenda.
     */
    public function comoSeLee(): string
    {
        $umbral = $this->umbral_fuente === self::FUENTE_PLAN
            ? 'el mínimo del plan'
            : rtrim(rtrim(number_format((float) $this->umbral, 2, '.', ''), '0'), '.');

        $ventana = match ($this->ventana_tipo) {
            'ultimos_dias' => 'en los últimos '.$this->ventana_valor.' días',
            'desde_inicio' => 'desde que ingresó',
            default => 'en el ciclo',
        };

        return $this->metrica.' '.$this->comparador.' '.$umbral.' '.$ventana;
    }
}
