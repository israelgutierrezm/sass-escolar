<?php

declare(strict_types=1);

namespace App\Models\ControlEscolar;

use App\Models\Concerns\TieneAuditoria;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * docente_asignatura_grupo (TENANT) — un docente en UNA materia de un grupo.
 *
 * ── Por qué existe un modelo para un pivote ──────────────────────────────
 * Durante mucho tiempo esta tabla sólo se tocó a través de las relaciones de
 * `AsignaturaGrupo` y `Docente`, y estaba bien: nadie preguntaba por la
 * asignación en sí. La carga académica sí lo hace —«qué materia da quién, en
 * qué grupo y de qué ciclo» es una fila POR ASIGNACIÓN, no por docente ni por
 * materia—, y un motor de reportes necesita un modelo con su Builder.
 *
 * ── El `id` llegó tarde, y por eso importa ───────────────────────────────
 * La tabla nació con PK compuesta `(asignatura_grupo_id, persona_id)`. Eso
 * impedía dos cosas: recorrerla por lotes —el cursor del motor avanza con UNA
 * columna— y retirarla conservándola —la fila dada de baja seguía ocupando el
 * par y volver a asignar reventaba con `Duplicate entry` para siempre—. La
 * migración `2026_08_26_090000` le dio llave propia y movió el único a
 * `(asignatura_grupo_id, persona_id, deleted_at)`, que en MySQL admite muchas
 * retiradas y **una sola viva**.
 *
 * ── Sobre `tipo` ─────────────────────────────────────────────────────────
 * `titular` es quien firma el acta; `adjunto` acompaña. A lo más un titular por
 * materia, y esa regla vive en la APLICACIÓN porque MySQL no admite un índice
 * único parcial. Una fuente que se apoyara en «hay a lo más un titular» para
 * darse una llave única estaría apoyándose en una regla que la base no
 * sostiene.
 */
class AsignacionDocente extends Model
{
    use TieneAuditoria;

    protected $table = 'docente_asignatura_grupo';

    protected $fillable = [
        'asignatura_grupo_id',
        'persona_id',
        'tipo',
    ];

    public function asignaturaGrupo(): BelongsTo
    {
        return $this->belongsTo(AsignaturaGrupo::class, 'asignatura_grupo_id');
    }

    /** El docente. La PK de `docentes` es `persona_id`, no un id propio. */
    public function docente(): BelongsTo
    {
        return $this->belongsTo(Docente::class, 'persona_id', 'persona_id');
    }

    public function esTitular(): bool
    {
        return $this->tipo === 'titular';
    }
}
