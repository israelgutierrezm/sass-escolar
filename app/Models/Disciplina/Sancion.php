<?php

declare(strict_types=1);

namespace App\Models\Disciplina;

use App\Models\Admisiones\MatriculaOferta;
use App\Models\Concerns\TieneAuditoria;
use App\Models\Identidad\Persona;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * sanciones (TENANT) — una consecuencia aplicada a un alumno.
 *
 * `desde`/`hasta` sólo se llenan cuando el tipo `tiene_vigencia` (una
 * suspension); una amonestacion es puntual y los deja en NULL.
 */
class Sancion extends Model
{
    use TieneAuditoria;

    protected $table = 'sanciones';

    protected $fillable = [
        'matricula_oferta_id',
        'tipo_sancion_id',
        'fecha',
        'desde',
        'hasta',
        'motivo',
        'aplicada_por',
    ];

    protected function casts(): array
    {
        return [
            'fecha' => 'date',
            'desde' => 'date',
            'hasta' => 'date',
        ];
    }

    public function matricula(): BelongsTo
    {
        return $this->belongsTo(MatriculaOferta::class, 'matricula_oferta_id');
    }

    public function tipo(): BelongsTo
    {
        return $this->belongsTo(TipoSancion::class, 'tipo_sancion_id');
    }

    public function aplica(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'aplicada_por');
    }

    /** Las incidencias que la originaron (puede no citar ninguna). */
    public function incidencias(): BelongsToMany
    {
        return $this->belongsToMany(Incidencia::class, 'incidencia_sancion', 'sancion_id', 'incidencia_id');
    }

    /** Sigue en curso hoy: es de vigencia y la fecha de hoy cae dentro. */
    public function vigente(): bool
    {
        return $this->desde !== null
            && $this->hasta !== null
            && now()->betweenIncluded($this->desde, $this->hasta);
    }
}
