<?php

declare(strict_types=1);

namespace App\Models\Permanencia;

use App\Models\Admisiones\MatriculaOferta;
use App\Models\Concerns\TieneAuditoria;
use App\Models\ControlEscolar\AsignaturaGrupo;
use App\Models\ControlEscolar\Ciclo;
use App\Models\Identidad\Usuario;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * alertas (TENANT) — una señal que cruzó su umbral.
 *
 * ── DOS estados, y ninguno sobra ───────────────────────────────────────────
 *  - `estado_senal` lo mueve el MOTOR: activa → resuelta (dejó de ser cierta,
 *    con la evidencia de la mejora) u obsoleta (se dejó de vigilar).
 *  - `estado_triage` lo mueve una PERSONA: nueva → validada o descartada.
 *
 * Son ejes independientes a propósito. Una alerta puede estar RESUELTA y
 * VALIDADA a la vez: alguien la miró, abrió un caso, y mientras tanto la
 * asistencia se recuperó. Fundidos en un solo estado habría que elegir cuál de
 * las dos cosas contar, y las dos son ciertas.
 *
 * ── «Resuelta» no es «obsoleta» ────────────────────────────────────────────
 * La primera dice que la situación mejoró; la segunda, que se apagó la regla o
 * el alumno salió de su alcance. Llamarlas igual haría que apagar una regla se
 * leyera como que doscientos alumnos se recuperaron, y ese número acabaría en
 * un informe.
 *
 * ── Descartar es una afirmación HUMANA y el motor no la contradice ─────────
 * Una descartada no vuelve a evaluarse. Lo que impide que nazca otra al día
 * siguiente es el enfriamiento de la regla, no una excepción del motor: son dos
 * mecanismos y hacen falta los dos.
 */
class Alerta extends Model
{
    use TieneAuditoria;

    protected $table = 'alertas';

    /** Lo que dice el motor. */
    public const ACTIVA = 'activa';

    public const RESUELTA = 'resuelta';

    public const OBSOLETA = 'obsoleta';

    /**
     * Los estados de SEÑAL en los que sigue viva.
     *
     * Escrito también en el SQL de la columna generada `clave_dedup`, que la
     * evalúa MySQL y no puede leer esta constante. **Una prueba los cruza**: sin
     * quien las compare se separan el día que se agregue un estado, y el único
     * empezaría a permitir o impedir lo que no debe, sin fallar.
     */
    public const ABIERTOS = [self::ACTIVA];

    /** Lo que dice una persona. */
    public const NUEVA = 'nueva';

    public const VALIDADA = 'validada';

    public const DESCARTADA = 'descartada';

    protected $fillable = [
        'matricula_oferta_id',
        'regla_id',
        'regla_version_id',
        'categoria_id',
        'asignatura_grupo_id',
        'ciclo_id',
        'severidad',
        'estado_senal',
        'estado_triage',
        'valor_observado',
        'umbral',
        'cobertura',
        'ventana_desde',
        'ventana_hasta',
        'evidencia',
        'primera_vez_en',
        'ultima_evaluacion_en',
        'cerrada_en',
        'evidencia_cierre',
        'motivo_descarte_id',
        'nota_triage',
        'revisada_por',
        'revisada_en',
    ];

    protected function casts(): array
    {
        return [
            'evidencia' => 'array',
            'evidencia_cierre' => 'array',
            'valor_observado' => 'float',
            'umbral' => 'float',
            'cobertura' => 'integer',
            'ventana_desde' => 'date',
            'ventana_hasta' => 'date',
            'primera_vez_en' => 'datetime',
            'ultima_evaluacion_en' => 'datetime',
            'cerrada_en' => 'datetime',
            'revisada_en' => 'datetime',
        ];
    }

    public function matricula(): BelongsTo
    {
        return $this->belongsTo(MatriculaOferta::class, 'matricula_oferta_id');
    }

    public function regla(): BelongsTo
    {
        return $this->belongsTo(ReglaAlerta::class, 'regla_id');
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(ReglaAlertaVersion::class, 'regla_version_id');
    }

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(CategoriaSenal::class, 'categoria_id');
    }

    public function asignaturaGrupo(): BelongsTo
    {
        return $this->belongsTo(AsignaturaGrupo::class, 'asignatura_grupo_id');
    }

    public function ciclo(): BelongsTo
    {
        return $this->belongsTo(Ciclo::class, 'ciclo_id');
    }

    public function motivoDescarte(): BelongsTo
    {
        return $this->belongsTo(MotivoDescarte::class, 'motivo_descarte_id');
    }

    public function revisadaPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'revisada_por');
    }

    /** Las que el motor sigue considerando ciertas. */
    public function scopeAbiertas(Builder $c): Builder
    {
        return $c->whereIn('estado_senal', self::ABIERTOS);
    }

    /** Las que esperan que una persona las mire. */
    public function scopePorRevisar(Builder $c): Builder
    {
        return $c->abiertas()->where('estado_triage', self::NUEVA);
    }

    public function estaAbierta(): bool
    {
        return in_array($this->estado_senal, self::ABIERTOS, true);
    }

    /**
     * Lo que se le enseña a quien NO alcanza el detalle de su categoría.
     *
     * Sensible no es invisible: quien no la alcanza ve QUE HAY una señal de esa
     * categoría —lo que le permite llamar a quien corresponde— y no ve el valor,
     * el umbral ni la evidencia. Se resuelve AQUÍ y no en cada pantalla: escrito
     * seis veces, el que se olvide enseña el dato.
     *
     * @return array<string, mixed>
     */
    public function comoLaVe(?Usuario $usuario): array
    {
        $comun = [
            'id' => $this->id,
            'categoria' => $this->categoria?->only(['id', 'clave', 'nombre', 'color', 'sensible']),
            'severidad' => $this->severidad,
            'estado_senal' => $this->estado_senal,
            'estado_triage' => $this->estado_triage,
            'primera_vez_en' => $this->primera_vez_en?->toDateTimeString(),
            'regla' => $this->regla?->nombre,
        ];

        if ($this->categoria?->alcanzaElDetalle($usuario) !== true) {
            return $comun + [
                'reservada' => true,
                /*
                 * Se dice QUÉ falta para verlo, no sólo que no se puede. Un
                 * «reservado» pelado manda a la gente a soporte; nombrando el
                 * permiso, quien lo lee sabe a quién llamar.
                 */
                'motivo' => 'El detalle de esta categoría exige el permiso «'
                    .($this->categoria?->permiso_detalle ?? '—').'».',
            ];
        }

        return $comun + [
            'reservada' => false,
            'valor_observado' => $this->valor_observado,
            'umbral' => $this->umbral,
            'cobertura' => $this->cobertura,
            'evidencia' => $this->evidencia,
            'evidencia_cierre' => $this->evidencia_cierre,
            'condicion' => $this->version?->comoSeLee(),
        ];
    }
}
