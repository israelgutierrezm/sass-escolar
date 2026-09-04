<?php

declare(strict_types=1);

namespace App\Models\ProcesosFormativos;

use App\Models\Concerns\TieneAuditoria;
use App\Models\Plataforma\Aviso;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * El rastro de que YA se avisó de algo. Sin él, el comando gotea cada mañana.
 *
 * ── Los eventos son claves de CÓDIGO, no un catálogo ──────────────────────
 * Cada uno es una rama con su texto, su destinatario y su condición: una fila
 * nueva en una tabla no haría nada. Es el mismo argumento que `tipos_actividad`
 * y que los requisitos excepcionables.
 */
class AlertaProceso extends Model
{
    use SoftDeletes, TieneAuditoria;

    /** Un informe cuya fecha límite se acerca. */
    public const INFORME_POR_VENCER = 'informe_por_vencer';

    /** Se le pasó la fecha y sigue sin entregarlo. */
    public const INFORME_VENCIDO = 'informe_vencido';

    /** El periodo del proceso está por terminar. */
    public const PLAZO_POR_VENCER = 'plazo_por_vencer';

    /** Terminó el periodo y el expediente sigue en curso. */
    public const PLAZO_VENCIDO = 'plazo_vencido';

    /** Ya cumplió todo y nadie lo ha liberado: es de la ESCUELA, no del alumno. */
    public const LISTO_PARA_LIBERAR = 'listo_para_liberar';

    /** @var array<string, string> */
    public const EVENTOS = [
        self::INFORME_POR_VENCER => 'Informe por vencer',
        self::INFORME_VENCIDO => 'Informe vencido',
        self::PLAZO_POR_VENCER => 'Plazo por vencer',
        self::PLAZO_VENCIDO => 'Plazo vencido',
        self::LISTO_PARA_LIBERAR => 'Listo para liberar',
    ];

    protected $table = 'alertas_proceso';

    protected $fillable = ['expediente_id', 'evento', 'referencia', 'aviso_id', 'emitida_en'];

    protected function casts(): array
    {
        return ['emitida_en' => 'datetime'];
    }

    public function expediente(): BelongsTo
    {
        return $this->belongsTo(ExpedienteProceso::class, 'expediente_id');
    }

    public function aviso(): BelongsTo
    {
        return $this->belongsTo(Aviso::class, 'aviso_id');
    }

    public function etiqueta(): string
    {
        return self::EVENTOS[$this->evento] ?? $this->evento;
    }
}
