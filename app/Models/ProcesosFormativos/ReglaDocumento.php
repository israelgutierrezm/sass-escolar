<?php

declare(strict_types=1);

namespace App\Models\ProcesosFormativos;

use App\Models\Admisiones\DocumentoRequerido;
use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * regla_documentos (TENANT) — qué papel pide una versión de la regla, y cuándo.
 *
 * ── El catálogo de papeles se REUSA ────────────────────────────────────────
 * `documentos_requeridos` ya sabe de vigencia y de estados de revisión, y es el
 * mismo papel que el expediente del alumno. Clonarlo daría dos sitios donde
 * buscar «comprobante de seguro facultativo» y dos estados del mismo trámite.
 *
 * ── `momento` separa tres cosas distintas ──────────────────────────────────
 * Pedirlo todo al solicitar frenaría la solicitud por una carta de término que
 * todavía no existe; pedirlo todo al liberar dejaría empezar sin seguro.
 */
class ReglaDocumento extends Model
{
    use TieneAuditoria;

    protected $table = 'regla_documentos';

    protected $attributes = ['momento' => ReglaProcesoVersion::MOMENTO_SOLICITUD, 'obligatorio' => true];

    protected $fillable = ['version_id', 'documento_id', 'momento', 'obligatorio', 'dias_vigencia'];

    protected function casts(): array
    {
        return ['obligatorio' => 'boolean', 'dias_vigencia' => 'integer'];
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(ReglaProcesoVersion::class, 'version_id');
    }

    public function documento(): BelongsTo
    {
        return $this->belongsTo(DocumentoRequerido::class, 'documento_id');
    }
}
