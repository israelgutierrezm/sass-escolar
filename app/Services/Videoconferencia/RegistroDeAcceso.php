<?php

declare(strict_types=1);

namespace App\Services\Videoconferencia;

use App\Models\Lms\AccesoVideoconferencia;
use App\Models\Lms\Videoconferencia;
use Illuminate\Support\Facades\DB;

/**
 * Anota que alguien pulsó «Entrar a la clase».
 *
 * ── Idempotente por (clase, persona), y a propósito ────────────────────────
 * El segundo clic NO crea otra fila: sube `veces` y mueve `ultimo_acceso`. Es la
 * decisión que hace que «¿cuántos entraron?» sea un `count()` y no un
 * `count(distinct)` que alguien olvidará. Ver la migración.
 *
 * ── Y se hace con `upsert`, no con «buscar y si no crear» ──────────────────
 * Un alumno que pulsa dos veces seguidas —el botón no responde rápido y vuelve a
 * picarle— manda dos peticiones a la vez, y el par SELECT+INSERT las deja pasar
 * a las dos: la segunda revienta contra el índice único y le devuelve un error
 * de base a alguien que sólo quería entrar a clase. Con `ON DUPLICATE KEY` la
 * carrera se resuelve en el motor.
 */
class RegistroDeAcceso
{
    public function registrar(Videoconferencia $clase, int $personaId, string $papel): void
    {
        $ahora = now();

        DB::connection('tenant')->statement(
            <<<'SQL'
                INSERT INTO accesos_videoconferencia
                    (videoconferencia_id, persona_id, primer_acceso, ultimo_acceso, veces, papel,
                     created_at, updated_at, created_by, updated_by)
                VALUES (?, ?, ?, ?, 1, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    ultimo_acceso = VALUES(ultimo_acceso),
                    veces         = veces + 1,
                    updated_at    = VALUES(updated_at),
                    updated_by    = VALUES(updated_by)
                SQL,
            [
                $clase->id,
                $personaId,
                $ahora,
                $ahora,
                $papel,
                $ahora,
                $ahora,
                $personaId,
                $personaId,
            ]
        );
    }

    /**
     * Quiénes entraron a esta clase, para enseñárselo al docente.
     *
     * @return \Illuminate\Support\Collection<int, AccesoVideoconferencia>
     */
    public function deLaClase(Videoconferencia $clase)
    {
        return AccesoVideoconferencia::query()
            ->where('videoconferencia_id', $clase->id)
            ->with('persona:id,nombre,primer_apellido,segundo_apellido')
            // Por orden de llegada: es como se lee una lista de asistencia, y
            // deja arriba a quien estuvo desde el principio.
            ->orderBy('primer_acceso')
            ->get();
    }
}
