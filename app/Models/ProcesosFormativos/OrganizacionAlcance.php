<?php

declare(strict_types=1);

namespace App\Models\ProcesosFormativos;

use App\Models\Academico\Campus;
use App\Models\Academico\ProgramaAcademico;
use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * organizacion_alcances (TENANT) — hasta dónde llega una receptora.
 *
 * Cada fila es un permiso INDEPENDIENTE y basta que una case: «prácticas de
 * Enfermería» y «lo que sea del campus Norte» son dos alcances legítimos de la
 * misma organización. Dentro de una fila, lo declarado tiene que coincidir y lo
 * que está en null no acota.
 *
 * **Sin ninguna fila, la organización alcanza a TODO.** Ver
 * {@see OrganizacionReceptora::alcanzaA()}.
 */
class OrganizacionAlcance extends Model
{
    use TieneAuditoria;

    protected $table = 'organizacion_alcances';

    protected $fillable = ['organizacion_id', 'campus_id', 'programa_academico_id', 'tipo_proceso_id'];

    public function organizacion(): BelongsTo
    {
        return $this->belongsTo(OrganizacionReceptora::class, 'organizacion_id');
    }

    public function campus(): BelongsTo
    {
        return $this->belongsTo(Campus::class, 'campus_id');
    }

    public function programaAcademico(): BelongsTo
    {
        return $this->belongsTo(ProgramaAcademico::class, 'programa_academico_id');
    }

    public function tipoProceso(): BelongsTo
    {
        return $this->belongsTo(TipoProcesoFormativo::class, 'tipo_proceso_id');
    }

    /** Cómo se lee en pantalla. «Cualquiera» donde no acota. */
    public function comoSeLee(): string
    {
        return implode(' · ', [
            $this->campus?->nombre ?? 'Cualquier campus',
            $this->programaAcademico?->nombre ?? 'Cualquier programa',
            $this->tipoProceso?->nombre ?? 'Cualquier proceso',
        ]);
    }
}
