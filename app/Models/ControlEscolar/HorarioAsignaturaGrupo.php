<?php

declare(strict_types=1);

namespace App\Models\ControlEscolar;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * horarios_asignatura_grupo (TENANT) — un bloque de horario por fila.
 */
class HorarioAsignaturaGrupo extends Model
{
    use TieneAuditoria;

    protected $table = 'horarios_asignatura_grupo';

    protected $fillable = [
        'asignatura_grupo_id',
        'dia_semana',
        'hora_inicio',
        'hora_fin',
        'aula_id',
    ];

    public function asignaturaGrupo(): BelongsTo
    {
        return $this->belongsTo(AsignaturaGrupo::class, 'asignatura_grupo_id');
    }

    public function aula(): BelongsTo
    {
        return $this->belongsTo(Aula::class);
    }

    /** ¿Este bloque se traslapa con otro el mismo día? */
    public function chocaCon(self $otro): bool
    {
        return $this->dia_semana === $otro->dia_semana
            && $this->hora_inicio < $otro->hora_fin
            && $otro->hora_inicio < $this->hora_fin;
    }
}
