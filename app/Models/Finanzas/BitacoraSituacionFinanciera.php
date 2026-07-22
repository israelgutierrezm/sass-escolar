<?php

declare(strict_types=1);

namespace App\Models\Finanzas;

use App\Models\Admisiones\MatriculaOferta;
use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * bitacora_situacion_financiera (TENANT) — cómo fue cambiando la situación de
 * pago de una matrícula.
 *
 * Append-only: levantar un bloqueo agrega un renglón, no borra el anterior. La
 * pregunta que se hace meses después es "¿por qué no se pudo reinscribir en
 * marzo?", y eso solo lo responde saber qué situación tenía ENTONCES.
 */
class BitacoraSituacionFinanciera extends Model
{
    use TieneAuditoria;

    protected $table = 'bitacora_situacion_financiera';

    protected $fillable = [
        'matricula_oferta_id',
        'situacion_id',
        'motivo',
        'momento',
    ];

    protected function casts(): array
    {
        return ['momento' => 'datetime'];
    }

    public function matriculaOferta(): BelongsTo
    {
        return $this->belongsTo(MatriculaOferta::class, 'matricula_oferta_id');
    }

    public function situacion(): BelongsTo
    {
        return $this->belongsTo(SituacionPago::class, 'situacion_id');
    }

    /**
     * Registra un cambio de situación. Es el único camino: la situación
     * financiera vigente ES el último renglón de la bitácora, no una columna
     * que alguien pueda pisar sin dejar rastro.
     */
    public static function registrar(
        int $matriculaOfertaId,
        int $situacionId,
        ?string $motivo = null,
    ): self {
        return self::create([
            'matricula_oferta_id' => $matriculaOfertaId,
            'situacion_id' => $situacionId,
            'motivo' => $motivo,
            'momento' => now(),
        ]);
    }

    /** La situación financiera vigente de una matrícula, o null si nunca se registró. */
    public static function vigenteDe(int $matriculaOfertaId): ?self
    {
        return self::query()
            ->where('matricula_oferta_id', $matriculaOfertaId)
            ->orderByDesc('momento')
            ->orderByDesc('id')
            ->first();
    }
}
