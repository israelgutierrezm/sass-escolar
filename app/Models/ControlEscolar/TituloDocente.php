<?php

declare(strict_types=1);

namespace App\Models\ControlEscolar;

use App\Models\Concerns\TieneAuditoria;
use App\Models\Identidad\Persona;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * titulos_docente (TENANT) — un grado/título del docente (CV académico).
 */
class TituloDocente extends Model
{
    use TieneAuditoria;

    protected $table = 'titulos_docente';

    protected $fillable = [
        'persona_id',
        'grado',
        'titulo_obtenido',
        'cedula',
        'institucion',
        'anio',
        'archivo_url',
    ];

    protected function casts(): array
    {
        return ['anio' => 'integer'];
    }

    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'persona_id');
    }

    /** URL autenticada del documento del título; null si no tiene. */
    public function urlArchivo(): ?string
    {
        return $this->archivo_url === null ? null : "/escolar/docentes/titulos/{$this->id}/archivo";
    }
}
