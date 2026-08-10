<?php

declare(strict_types=1);

namespace App\Models\ControlEscolar;

use App\Models\Admisiones\Alumno;
use App\Models\Admisiones\DocumentoRequerido;
use App\Models\Admisiones\EstadoDocumento;
use App\Models\Concerns\TieneAuditoria;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * documentos_alumno (TENANT) — un comprobante del expediente del alumno.
 *
 * Espejo de {@see DocumentoDocente}: mismo trato para dos personas distintas de
 * la escuela. No se confunde con `expediente_documentos`, que es el expediente
 * de ADMISIÓN y cuelga del aspirante; éste vive todo el plan de estudios.
 */
class DocumentoAlumno extends Model
{
    use TieneAuditoria;

    protected $table = 'documentos_alumno';

    protected $fillable = [
        'persona_id',
        'documento_id',
        'descripcion',
        'url',
        'estado_documento_id',
        'vigencia',
        'observaciones',
    ];

    protected function casts(): array
    {
        return [
            'vigencia' => 'date',
        ];
    }

    public function alumno(): BelongsTo
    {
        return $this->belongsTo(Alumno::class, 'persona_id', 'persona_id');
    }

    public function documento(): BelongsTo
    {
        return $this->belongsTo(DocumentoRequerido::class, 'documento_id');
    }

    public function estado(): BelongsTo
    {
        return $this->belongsTo(EstadoDocumento::class, 'estado_documento_id');
    }

    /** Un documento con vigencia pasada ya no acredita nada. */
    public function estaVencido(?string $fecha = null): bool
    {
        if ($this->vigencia === null) {
            return false;
        }

        return $this->vigencia->lt($fecha === null ? now()->startOfDay() : Carbon::parse($fecha));
    }
}
