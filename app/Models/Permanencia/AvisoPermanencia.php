<?php

declare(strict_types=1);

namespace App\Models\Permanencia;

use App\Models\Admisiones\MatriculaOferta;
use App\Models\Concerns\TieneAuditoria;
use App\Models\Plataforma\Aviso;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * avisos_permanencia (TENANT) — de qué ya se avisó, para no volver a avisar.
 *
 * ── Los eventos, y por qué son claves de CÓDIGO y no un catálogo ───────────
 * Cada uno es una rama con su condición, su destinatario y su momento. Una fila
 * nueva en una tabla no produciría ninguna de las tres — es el mismo argumento
 * que `tipos_actividad` y el que `AlertasFormativas` ya escribió para sus
 * textos. Cuando la escuela quiera redactarlos, el catálogo llegará con su
 * lector.
 */
class AvisoPermanencia extends Model
{
    use TieneAuditoria;

    protected $table = 'avisos_permanencia';

    /** Señales validadas que el alumno todavía no sabía. */
    public const SENALES_AL_ALUMNO = 'senales_al_alumno';

    /** Señales sin revisar esperando en la bandeja. */
    public const SENALES_POR_REVISAR = 'senales_por_revisar';

    /** Se pasó el compromiso de primer contacto y nadie ha hablado con nadie. */
    public const SLA_VENCIDO = 'sla_vencido';

    /** Lleva días abierto y nadie se ha hecho cargo. */
    public const CASO_SIN_ASIGNAR = 'caso_sin_asignar';

    /** Una tarea del caso pasó su fecha. */
    public const TAREA_VENCIDA = 'tarea_vencida';

    /** Lo que se agendó es para hoy. */
    public const INTERVENCION_HOY = 'intervencion_hoy';

    protected $fillable = [
        'caso_id',
        'matricula_oferta_id',
        'evento',
        'referencia',
        'aviso_id',
        'emitida_en',
        'destinatarios',
    ];

    protected function casts(): array
    {
        return [
            'emitida_en' => 'datetime',
            'destinatarios' => 'integer',
        ];
    }

    public function caso(): BelongsTo
    {
        return $this->belongsTo(CasoPermanencia::class, 'caso_id');
    }

    public function matricula(): BelongsTo
    {
        return $this->belongsTo(MatriculaOferta::class, 'matricula_oferta_id');
    }

    public function aviso(): BelongsTo
    {
        return $this->belongsTo(Aviso::class, 'aviso_id');
    }

    public function scopeDeHoy(Builder $c, ?string $dia = null): Builder
    {
        return $c->whereDate('emitida_en', $dia ?? now()->toDateString());
    }
}
