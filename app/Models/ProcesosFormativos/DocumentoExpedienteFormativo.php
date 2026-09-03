<?php

declare(strict_types=1);

namespace App\Models\ProcesosFormativos;

use App\Models\Admisiones\DocumentoRequerido;
use App\Models\Admisiones\EstadoDocumento;
use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Un papel entregado para ESTE trámite.
 *
 * ── Tabla propia, y no una columna en `expediente_documentos` ─────────────
 * Aquélla es de admisiones y cuelga de `aspirantes`. Ésta es la cuarta con la
 * misma forma tras `documentos_alumno`, `documentos_docente` y
 * `documentos_tutor`, y se repite a propósito: con una sola tabla, los papeles
 * de un servicio social asomarían en el expediente de admisión de quien es las
 * dos cosas.
 *
 * ── El CATÁLOGO sí se reusa ───────────────────────────────────────────────
 * `documentos_requeridos` con el ámbito `proceso_formativo`. «Comprobante de
 * seguro facultativo» es el mismo papel que ya sabe tener vigencia y estados;
 * clonarlo daría dos listas que divergirían.
 */
class DocumentoExpedienteFormativo extends Model
{
    use SoftDeletes, TieneAuditoria;

    protected $table = 'documentos_expediente_formativo';

    protected $fillable = [
        'expediente_id', 'documento_id', 'momento', 'ruta', 'nombre_original',
        'estado_documento_id', 'vigencia', 'observaciones',
    ];

    protected function casts(): array
    {
        return ['vigencia' => 'date'];
    }

    public function expediente(): BelongsTo
    {
        return $this->belongsTo(ExpedienteProceso::class, 'expediente_id');
    }

    public function documento(): BelongsTo
    {
        return $this->belongsTo(DocumentoRequerido::class, 'documento_id');
    }

    public function estado(): BelongsTo
    {
        return $this->belongsTo(EstadoDocumento::class, 'estado_documento_id');
    }

    /**
     * ¿Está entregado Y sirve?
     *
     * Vencido no es lo mismo que faltante, y las dos cosas se dicen distinto:
     * quien lo entregó y se le venció no tiene que volver a buscarlo desde
     * cero. Sin `vigencia` capturada no caduca.
     */
    public function estaVigente(?string $dia = null): bool
    {
        if ($this->ruta === null) {
            return false;
        }

        return $this->vigencia === null
            || $this->vigencia->toDateString() >= ($dia ?? now()->toDateString());
    }
}
