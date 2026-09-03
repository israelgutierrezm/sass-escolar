<?php

declare(strict_types=1);

namespace App\Models\ProcesosFormativos;

use App\Models\Identidad\Usuario;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un renglón de la historia del expediente. APPEND-ONLY.
 *
 * ── Por qué guarda el ORIGEN y no sólo el destino ─────────────────────────
 * Con sólo el destino, reconstruir de dónde venía exige recorrer la bitácora en
 * orden y confiar en que no falte ningún renglón. Guardando los dos, cada fila
 * se explica sola — y el renglón del ALTA lleva el origen en null, que es lo
 * que da un «desde cuándo» a cualquier medición de tiempos. Es la lección de
 * `PostulacionBitacora`.
 *
 * ── Y guarda la IP ────────────────────────────────────────────────────────
 * Aprobar un servicio social es un acto con consecuencias académicas. Sin la
 * IP, «yo no fui» no se puede contrastar con nada.
 */
class TransicionExpediente extends Model
{
    protected $table = 'expediente_transiciones';

    protected $fillable = [
        'expediente_id', 'estado_origen', 'estado_destino', 'motivo', 'usuario_id', 'ip', 'momento',
    ];

    protected function casts(): array
    {
        return [
            'estado_origen' => EstadoExpediente::class,
            'estado_destino' => EstadoExpediente::class,
            'momento' => 'datetime',
        ];
    }

    public function expediente(): BelongsTo
    {
        return $this->belongsTo(ExpedienteProceso::class, 'expediente_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }
}
