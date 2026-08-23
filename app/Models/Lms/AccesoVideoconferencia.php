<?php

declare(strict_types=1);

namespace App\Models\Lms;

use App\Models\Concerns\TieneAuditoria;
use App\Models\Identidad\Persona;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * accesos_videoconferencia (TENANT) — un clic en «Entrar a la clase».
 *
 * Mide que la persona PIDIÓ el enlace con la clase abierta. No mide permanencia:
 * ver la migración, donde está el porqué y qué costaría medirla de verdad.
 */
class AccesoVideoconferencia extends Model
{
    use TieneAuditoria;

    /** Entró como quien recibe la clase. */
    public const ALUMNO = 'alumno';

    /** Entró como quien la imparte. */
    public const DOCENTE = 'docente';

    protected $table = 'accesos_videoconferencia';

    protected $fillable = [
        'videoconferencia_id',
        'persona_id',
        'primer_acceso',
        'ultimo_acceso',
        'veces',
        'papel',
    ];

    protected function casts(): array
    {
        return [
            'primer_acceso' => 'datetime',
            'ultimo_acceso' => 'datetime',
            'veces' => 'integer',
        ];
    }

    public function videoconferencia(): BelongsTo
    {
        return $this->belongsTo(Videoconferencia::class, 'videoconferencia_id');
    }

    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'persona_id');
    }

    /**
     * Cuánto tardó en entrar desde que empezó la clase, en minutos.
     *
     * Negativo si llegó antes de la hora, que es lo normal —el botón abre unos
     * minutos antes—. Es el único dato de puntualidad que este mecanismo puede
     * dar honestamente, y por eso se calcula sobre el PRIMER acceso: el último
     * sube con cada reconexión y convertiría a quien se le cayó el internet en
     * alguien que llegó tarde.
     */
    public function minutosDeRetraso(): ?int
    {
        $inicio = $this->videoconferencia?->inicio;

        if ($inicio === null || $this->primer_acceso === null) {
            return null;
        }

        return (int) round($inicio->diffInSeconds($this->primer_acceso, false) / 60);
    }
}
