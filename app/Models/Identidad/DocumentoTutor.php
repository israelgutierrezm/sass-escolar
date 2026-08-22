<?php

declare(strict_types=1);

namespace App\Models\Identidad;

use App\Models\Admisiones\DocumentoRequerido;
use App\Models\Admisiones\EstadoDocumento;
use App\Models\Concerns\TieneAuditoria;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * documentos_tutor (TENANT) — un comprobante del expediente del tutor familiar.
 *
 * Espejo de `DocumentoAlumno` y `DocumentoDocente`: son los papeles DEL TUTOR
 * —su identificación, su comprobante de domicilio— y no los de sus hijos, que
 * tienen su propia tabla y su propia pantalla.
 *
 * Vive en `Identidad` y no en `ControlEscolar` porque el tutor familiar no es
 * una figura escolar: es la persona responsable de un alumno, y su vínculo
 * (`TutorAlumno`) también vive aquí.
 */
class DocumentoTutor extends Model
{
    use TieneAuditoria;

    protected $table = 'documentos_tutor';

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

    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'persona_id');
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
