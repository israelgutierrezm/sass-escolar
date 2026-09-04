<?php

declare(strict_types=1);

namespace App\Models\Permanencia;

use Illuminate\Database\Eloquent\Model;

/**
 * corridas_evaluacion (TENANT) — qué hizo el motor cada vez que corrió.
 *
 * ── Es la observabilidad, y hace falta porque esto corre de madrugada ──────
 * Un comando programado que nadie mira se queda sin mirar durante meses. Aquí
 * queda cuánto tardó, a cuántos evaluó, cuántas alertas salieron y —sobre
 * todo— **qué reglas fallaron, con su nombre**: con un id habría que cruzarlo
 * contra una tabla, y esto lo lee quien administra a las siete de la mañana.
 *
 * ── Sin `TieneAuditoria`, a propósito ──────────────────────────────────────
 * Es una bitácora append-only escrita por una máquina: `created_by` sería
 * siempre null y `updated_by` nunca cambiaría. Quien la disparó a mano va en
 * `corrida_por`, que es el dato que de verdad significa algo.
 */
class CorridaEvaluacion extends Model
{
    protected $table = 'corridas_evaluacion';

    protected $fillable = [
        'iniciada_en',
        'terminada_en',
        'disparo',
        'matriculas_evaluadas',
        'reglas_evaluadas',
        'alertas_creadas',
        'alertas_actualizadas',
        'alertas_resueltas',
        'alertas_obsoletas',
        'sin_datos',
        'errores',
        'milisegundos',
        'corrida_por',
    ];

    protected function casts(): array
    {
        return [
            'iniciada_en' => 'datetime',
            'terminada_en' => 'datetime',
            'errores' => 'array',
        ];
    }

    /** Si alguna regla reventó. El comando sale con error cuando esto es cierto. */
    public function huboErrores(): bool
    {
        return ! empty($this->errores);
    }
}
