<?php

declare(strict_types=1);

namespace App\Models\Academico;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * programas académicos (TENANT). `nivelEstudios` apunta al catálogo tenant homónimo.
 */
class ProgramaAcademico extends Model
{
    use TieneAuditoria;

    protected $table = 'programas_academicos';

    protected $fillable = [
        'identificador',
        'clave',
        'nombre',
        'nivel_estudios_id',
        'objetivo',
        'imagen_url',
        // Si expide documentos oficiales. Un diplomado o un curso de educación
        // continua vive en este mismo catálogo y no tiene RVOE que respalde ni
        // certificado ni título; y donde hay uno hay el otro, así que es un solo
        // permiso y no dos.
        'emite_documentos_oficiales',
    ];

    protected function casts(): array
    {
        return [
            'emite_documentos_oficiales' => 'boolean',
        ];
    }

    public function nivelEstudios(): BelongsTo
    {
        return $this->belongsTo(NivelEstudio::class, 'nivel_estudios_id');
    }

    /**
     * `cveCarrera` del título electrónico de la SEP: se reutiliza la `clave` de
     * el programa académico (por decisión, no hay columna oficial aparte). El
     * `identificador` es el id interno estable, no la clave oficial.
     */
    public function cveCarrera(): string
    {
        return $this->clave;
    }

    public function planes(): HasMany
    {
        return $this->hasMany(PlanEstudio::class, 'programa_academico_id');
    }
}
