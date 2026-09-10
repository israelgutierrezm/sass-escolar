<?php

declare(strict_types=1);

namespace App\Services\Familia;

use App\Models\Identidad\Autorizacion;
use App\Models\Identidad\Parentesco;
use App\Models\Identidad\TutorAlumno;
use App\Models\Identidad\Usuario;

/**
 * La familia responde y revoca una autorización.
 *
 * ── Una sola verdad ────────────────────────────────────────────────────────
 * Las dos escrituras vivían dentro de `AutorizacionController`; la app móvil
 * necesitaba lo mismo desde su API. En vez de copiar los guardas —de quién es y
 * en qué estado se puede tocar—, la regla vive aquí y la usan los dos.
 *
 * ── Los guardas responden 404, no 403 ──────────────────────────────────────
 * Ni el vínculo ajeno ni una autorización que ya no admite el acto revelan su
 * existencia: un 403 confirmaría que ese id existe. Es el mismo criterio del
 * portal del hijo.
 */
class RespuestaAutorizacion
{
    /**
     * Concede o niega. Sólo mientras el plazo sigue abierto y no esté ya
     * concedida —una concedida se RETIRA con {@see revocar}, no se cambia a
     * negada—; eso lo decide `admiteRespuesta()` en el modelo.
     */
    public function responder(Autorizacion $autorizacion, Usuario $usuario, bool $concedida, ?string $comentario): void
    {
        $this->exigirQueSeaSuya($autorizacion, $usuario);

        // Vencida no se contesta ni se cambia: nadie des-autoriza la excursión
        // el lunes siguiente.
        abort_unless($autorizacion->admiteRespuesta(), 404);

        $autorizacion->update([
            'concedida' => $concedida,
            'comentario' => $comentario,
            'fecha_respuesta' => now(),
        ]);
    }

    /**
     * La familia RETIRA lo que concedió. No se ata al plazo de respuesta:
     * revocar un consentimiento vigente es un derecho. Sólo alcanza a lo que está
     * EN VIGOR (`puedeRevocar()`), y queda distinta de una negada —`revocada_en`
     * lo dice—.
     */
    public function revocar(Autorizacion $autorizacion, Usuario $usuario, ?string $comentario): void
    {
        $this->exigirQueSeaSuya($autorizacion, $usuario);

        abort_unless($autorizacion->puedeRevocar(), 404);

        $autorizacion->update([
            'revocada_en' => now(),
            'comentario' => $comentario ?? $autorizacion->comentario,
        ]);
    }

    /**
     * Las autorizaciones que le tocan a este familiar, para su portal —lo que
     * falta contestar primero, después lo resuelto—. El estado sale del modelo
     * (`estado()`), así que la pantalla no puede contradecirlo.
     *
     * @return array<int, array<string, mixed>>
     */
    public function lista(Usuario $usuario): array
    {
        if ($usuario->persona_id === null) {
            return [];
        }

        return Autorizacion::query()
            ->whereIn(
                'vinculo_familiar_id',
                TutorAlumno::query()->where('tutor_persona_id', $usuario->persona_id)->select('id'),
            )
            ->with(['tipo:id,nombre', 'vinculo.alumno:id,nombre,primer_apellido,segundo_apellido'])
            ->orderByRaw('concedida IS NOT NULL')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Autorizacion $a) => [
                'id' => $a->id,
                'titulo' => $a->titulo,
                'detalle' => $a->detalle,
                'tipo' => $a->tipo?->nombre,
                'alumno' => $a->vinculo?->alumno?->nombreCompleto(),
                'parentesco' => Parentesco::nombreDe($a->vinculo?->parentesco_id),
                'fecha_limite' => $a->fecha_limite?->toDateString(),
                'vigencia_hasta' => $a->vigencia_hasta?->toDateString(),
                'vencida' => $a->estaVencida(),
                'concedida' => $a->concedida,
                'estado' => $a->estado(),
                'comentario' => $a->comentario,
                'fecha_respuesta' => $a->fecha_respuesta?->toDateTimeString(),
                'revocada_en' => $a->revocada_en?->toDateTimeString(),
                // Responder mientras el plazo siga abierto y NO esté concedida:
                // una concedida se RETIRA con revocar, no se cambia a negada.
                'puede_responder' => $a->admiteRespuesta() && $a->concedida !== true,
                'puede_revocar' => $a->puedeRevocar(),
            ])
            ->all();
    }

    /** El vínculo de la autorización tiene que ser de ESTE tutor. */
    private function exigirQueSeaSuya(Autorizacion $autorizacion, Usuario $usuario): void
    {
        $esSuya = $usuario->persona_id !== null
            && TutorAlumno::query()
                ->whereKey($autorizacion->vinculo_familiar_id)
                ->where('tutor_persona_id', $usuario->persona_id)
                ->exists();

        abort_unless($esSuya, 404);
    }
}
